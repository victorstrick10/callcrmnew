<details class="proxy-edit">
  <summary title="Edit the proxy target like the Multilogin GUI (auto-match stays the default)">✎ Edit target</summary>
  <form method="post" action="{{ route('appointments.proxy.get', $c->display_appointment_id) }}" class="proxy-edit-form">
    @csrf
    <input type="hidden" name="ov_edit" value="1">
    <label>Connection type
      <select name="ov_connection">
        <option value="mobile" selected>Mobile</option>
        <option value="residential">Residential</option>
        <option value="isp">ISP</option>
      </select>
    </label>
    <label>Country <small>(2-letter ISO)</small>
      <input type="text" name="ov_country" maxlength="2" value="{{ $c->geo_country_code }}" placeholder="AE">
    </label>
    <div class="pe-row">
      <label>Region <small>(optional)</small>
        <input type="text" name="ov_region" value="{{ $c->geo_region }}" placeholder="Dubai">
      </label>
      <label>City <small>(optional)</small>
        <input type="text" name="ov_city" value="{{ $c->geo_city }}" placeholder="Dubai">
      </label>
    </div>
    <label>ISP <small>(optional)</small>
      <input type="text" name="ov_isp" value="{{ $c->geo_provider }}" placeholder="Etisalat">
    </label>
    <label>Protocol
      <select name="ov_protocol">
        <option value="http" selected>HTTP</option>
        <option value="socks5">SOCKS5</option>
      </select>
    </label>
    <button class="mini-btn strong" type="submit" title="Build a Multilogin proxy using exactly these values">Build with these →</button>
  </form>
</details>
