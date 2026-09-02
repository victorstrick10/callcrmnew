<header class="topbar">
  <button class="icon-btn" id="menuToggle" type="button">☰</button>
  <div>
    <h1>@yield('page_title', 'Dashboard')</h1>
    <p>@yield('page_subtitle', 'Authorized marketing workspace automation')</p>
  </div>
  <div class="top-actions">
    <form method="post" action="{{ route('demo.appointment') }}">
      @csrf
      <button class="btn btn-secondary" type="submit">＋ Demo booking</button>
    </form>
    <div class="avatar">MP</div>
  </div>
</header>
