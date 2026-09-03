<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BrowserProfile;
use App\Models\ProfileNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProfileNumberService
{
    public function __construct(private AuditService $audit)
    {
    }

    /** @deprecated Use initializeForCompany / initializeAllCompanies */
    public function initialize(): void
    {
        $this->initializeAllCompanies();
    }

    public function initializeAllCompanies(): void
    {
        foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
            $this->initializeForCompany((int) $companyId);
        }
    }

    public function initializeForCompany(int $companyId): void
    {
        if (ProfileNumber::query()->where('company_id', $companyId)->exists()) {
            return;
        }

        $rows = [];
        for ($i = 1; $i <= 999; $i++) {
            $rows[] = [
                'company_id' => $companyId,
                'number' => $i,
                'status' => 'available',
                'profile_type' => '',
                'multilogin_profile_id' => '',
                'profile_name' => '',
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            ProfileNumber::query()->insert($chunk);
        }
    }

    public function nextNumber(int $companyId): int
    {
        $this->initializeForCompany($companyId);

        $row = ProfileNumber::query()
            ->where('company_id', $companyId)
            ->where('status', 'available')
            ->orderBy('number')
            ->first();

        if (! $row) {
            throw new RuntimeException('No available profile numbers remain between 001 and 999.');
        }

        return (int) $row->number;
    }

    /** @return list<int> */
    public function allocateNumbers(int $appointmentId, int $count): array
    {
        $number = $this->allocateNumberForAppointment($appointmentId);

        return array_fill(0, max(1, $count), $number);
    }

    /**
     * One number per appointment: GEO and STATIC for the same lead share it.
     * Next appointment gets the lowest free number in the company pool (fills gaps).
     */
    public function allocateNumberForAppointment(int $appointmentId): int
    {
        return DB::transaction(function () use ($appointmentId) {
            $companyId = (int) Appointment::query()->where('id', $appointmentId)->value('company_id');
            if ($companyId < 1) {
                throw new RuntimeException('Appointment has no company; cannot allocate a profile number.');
            }

            $this->initializeForCompany($companyId);

            // Reuse the number only when this lead already has a CREATED profile
            // — GEO + STATIC for the same lead share one number. Failed or stale
            // attempts must NOT lock a number.
            $existing = BrowserProfile::query()
                ->where('appointment_id', $appointmentId)
                ->where('status', 'created')
                ->where('number', '>', 0)
                ->orderBy('number')
                ->lockForUpdate()
                ->value('number');

            if ($existing) {
                return (int) $existing;
            }

            // Reuse a pool number only if it was actually CREATED for this lead.
            $fromPool = ProfileNumber::query()
                ->where('company_id', $companyId)
                ->where('appointment_id', $appointmentId)
                ->where('status', 'created')
                ->orderBy('number')
                ->lockForUpdate()
                ->value('number');

            if ($fromPool) {
                return (int) $fromPool;
            }

            // Release any stale reservation this lead is holding (from a failed or
            // abandoned earlier attempt) so allocation fills from the lowest free
            // number instead of jumping ahead.
            ProfileNumber::query()
                ->where('company_id', $companyId)
                ->where('appointment_id', $appointmentId)
                ->where('status', 'reserved')
                ->update([
                    'status' => 'available',
                    'appointment_id' => null,
                    'profile_type' => '',
                    'profile_name' => '',
                    'multilogin_profile_id' => '',
                    'reserved_at' => null,
                ]);

            $row = ProfileNumber::query()
                ->where('company_id', $companyId)
                ->where('status', 'available')
                ->orderBy('number')
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw new RuntimeException('No available profile numbers remain between 001 and 999.');
            }

            $row->status = 'reserved';
            $row->appointment_id = $appointmentId;
            $row->profile_type = '';
            $row->reserved_at = now();
            $row->multilogin_profile_id = '';
            $row->profile_name = '';
            $row->save();

            return (int) $row->number;
        });
    }

    public function formatNumber(int $number): string
    {
        return sprintf('%03d', $number);
    }

    public function extractNumber(?string $name): ?int
    {
        if (! preg_match('/^(\d+)\s+/', (string) $name, $match)) {
            return null;
        }
        $value = (int) $match[1];

        return $value >= 1 ? $value : null;
    }

    public function reserveNumbers(int $appointmentId, array $profileTypes): array
    {
        return DB::transaction(function () use ($appointmentId, $profileTypes) {
            $companyId = (int) Appointment::query()->where('id', $appointmentId)->value('company_id');
            if ($companyId < 1) {
                throw new RuntimeException('Appointment has no company; cannot reserve profile numbers.');
            }
            $this->initializeForCompany($companyId);

            $reserved = [];
            foreach ($profileTypes as $profileType) {
                $row = ProfileNumber::query()
                    ->where('company_id', $companyId)
                    ->where('status', 'available')
                    ->orderBy('number')
                    ->lockForUpdate()
                    ->first();

                if (! $row) {
                    throw new RuntimeException('No available profile numbers remain between 001 and 999.');
                }

                $row->status = 'reserved';
                $row->appointment_id = $appointmentId;
                $row->profile_type = $profileType;
                $row->reserved_at = now();
                $row->save();
                $reserved[] = $row->number;
            }

            return $reserved;
        });
    }

    public function releaseNumber(int $companyId, int $number): void
    {
        $row = ProfileNumber::query()
            ->where('company_id', $companyId)
            ->where('number', $number)
            ->first();

        if ($row && $row->status === 'reserved') {
            $row->status = 'available';
            $row->appointment_id = null;
            $row->profile_type = '';
            $row->profile_name = '';
            $row->reserved_at = null;
            $row->save();
        }
    }

    /**
     * @param  bool  $allowRelease  When true, numbers previously marked "created"
     *   that are absent from the fetched profile list are freed back to
     *   "available". This must be false for simulation runs and is force-disabled
     *   when the fetched list is empty, so a simulated or failed/empty fetch can
     *   never wipe the real number inventory shown on the Profile Numbers page.
     */
    public function syncFromProfiles(int $companyId, array $profiles, bool $allowRelease = true): array
    {
        $this->initializeForCompany($companyId);

        $occupied = [];
        foreach ($profiles as $profile) {
            $number = $this->extractNumber($profile['name'] ?? '');
            if ($number !== null && $number >= 1 && $number <= 999 && ! isset($occupied[$number])) {
                $occupied[$number] = $profile;
            }
        }

        // Only release stale "created" numbers when we trust the fetched list:
        // an explicit real (non-simulation) sync that actually returned profiles.
        $release = $allowRelease && count($profiles) > 0;

        foreach (ProfileNumber::query()->where('company_id', $companyId)->cursor() as $row) {
            if (isset($occupied[$row->number])) {
                $profile = $occupied[$row->number];
                $row->status = 'created';
                $row->multilogin_profile_id = (string) ($profile['id'] ?? '');
                $row->profile_name = (string) ($profile['name'] ?? '');
                $row->save();
            } elseif ($release && $row->status === 'created') {
                $row->status = 'available';
                $row->multilogin_profile_id = '';
                $row->profile_name = '';
                $row->appointment_id = null;
                $row->reserved_at = null;
                $row->created_at = null;
                $row->save();
            }
        }

        // Reconcile deletions: profiles removed on Multilogin are marked deleted
        // in the CRM so they no longer show as "created".
        if ($release) {
            $existingIds = array_values(array_filter(array_map(
                fn ($p) => (string) ($p['id'] ?? ''),
                $profiles
            )));

            BrowserProfile::query()
                ->whereHas('appointment', fn ($q) => $q->where('company_id', $companyId))
                ->where('status', 'created')
                ->where('multilogin_profile_id', '!=', '')
                ->whereNotIn('multilogin_profile_id', $existingIds)
                ->update(['status' => 'deleted']);
        }

        $usedNumbers = array_keys($occupied);
        sort($usedNumbers);
        $highestUsed = $usedNumbers ? max($usedNumbers) : 0;
        $usedSet = array_flip($usedNumbers);

        $nextFree = null;
        for ($n = 1; $n < 1000; $n++) {
            $status = ProfileNumber::query()
                ->where('company_id', $companyId)
                ->where('number', $n)
                ->value('status');
            if ($status === 'available' || ($status === null && ! isset($usedSet[$n]))) {
                $nextFree = $n;
                break;
            }
        }

        $this->audit->log(
            'Synchronized company Multilogin profile inventory',
            sprintf(
                'Company=%d; scanned %d profiles; marked %d numbers; highest=%s; next=%s.',
                $companyId,
                count($profiles),
                count($occupied),
                $highestUsed ?: 'none',
                $nextFree ? $this->formatNumber($nextFree) : 'none'
            )
        );

        return [
            'profiles_seen' => count($profiles),
            'numbers_marked' => count($occupied),
            'highest_used' => $highestUsed ?: null,
            'next_free' => $nextFree,
        ];
    }

    public function findForCompany(int $companyId, int $number): ?ProfileNumber
    {
        return ProfileNumber::query()
            ->where('company_id', $companyId)
            ->where('number', $number)
            ->first();
    }

    /**
     * Rename on Multilogin and in CRM. If the new name's leading number differs
     * (e.g. 159 … → 007 …), remaps the company pool row and browser_profiles.number.
     */
    public function renameProfile(ProfileNumber $row, string $name, MultiloginClient $client): ProfileNumber
    {
        $mlId = trim((string) $row->multilogin_profile_id);
        if ($mlId === '') {
            throw new RuntimeException('This number has no Multilogin profile id; sync first or create the profile.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Profile name is required.');
        }

        $newNumber = $this->extractNumber($name);
        if ($newNumber !== null && ($newNumber < 1 || $newNumber > 999)) {
            throw new RuntimeException('Leading profile number must be between 001 and 999.');
        }

        $client->update_profile_name($mlId, $name);

        return DB::transaction(function () use ($row, $name, $mlId, $newNumber) {
            /** @var ProfileNumber $row */
            $row = ProfileNumber::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $oldNumber = (int) $row->number;

            if ($newNumber === null || $newNumber === $oldNumber) {
                $row->profile_name = $name;
                $row->save();

                BrowserProfile::query()
                    ->where('multilogin_profile_id', $mlId)
                    ->update(['profile_name' => $name]);

                $this->audit->log(
                    'Renamed Multilogin profile',
                    sprintf('Company=%d; number=%s; name=%s', $row->company_id, $this->formatNumber($oldNumber), $name)
                );

                return $row;
            }

            $this->initializeForCompany((int) $row->company_id);

            $target = ProfileNumber::query()
                ->where('company_id', $row->company_id)
                ->where('number', $newNumber)
                ->lockForUpdate()
                ->first();

            if (! $target) {
                throw new RuntimeException('Target profile number '.$this->formatNumber($newNumber).' was not found in the company pool.');
            }

            if ($target->status !== 'available' && trim((string) $target->multilogin_profile_id) !== $mlId) {
                throw new RuntimeException(
                    'Number '.$this->formatNumber($newNumber).' is already in use by another profile.'
                );
            }

            $target->status = $row->status === 'available' ? 'created' : $row->status;
            $target->appointment_id = $row->appointment_id;
            $target->profile_type = $row->profile_type;
            $target->multilogin_profile_id = $mlId;
            $target->profile_name = $name;
            $target->reserved_at = $row->reserved_at;
            $target->created_at = $row->created_at ?: now();
            $target->save();

            $row->status = 'available';
            $row->appointment_id = null;
            $row->profile_type = '';
            $row->multilogin_profile_id = '';
            $row->profile_name = '';
            $row->reserved_at = null;
            $row->created_at = null;
            $row->save();

            BrowserProfile::query()
                ->where('multilogin_profile_id', $mlId)
                ->update([
                    'profile_name' => $name,
                    'number' => $newNumber,
                ]);

            $this->audit->log(
                'Remapped Multilogin profile number',
                sprintf(
                    'Company=%d; %s → %s; name=%s; ml=%s',
                    $target->company_id,
                    $this->formatNumber($oldNumber),
                    $this->formatNumber($newNumber),
                    $name,
                    $mlId
                )
            );

            return $target;
        });
    }
}
