<div class="modal-backdrop" id="progressModal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="progressTitle" style="width:min(640px,100%)">
    <div class="modal-head">
      <div>
        <h2 id="progressTitle">Creating profiles…</h2>
        <p id="progressSub">Running number check, proxy match &amp; Multilogin creation</p>
      </div>
      <button type="button" class="btn btn-secondary" id="progressClose" hidden>Close</button>
    </div>
    <div class="modal-body">
      <div id="progressSpinner" class="progress-spinner"><span class="spin"></span> Working… this can take a few seconds per profile.</div>
      <div id="progressLog" class="progress-log" hidden></div>
    </div>
  </div>
</div>
