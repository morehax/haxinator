<?php
/**
 * Web Terminal Module
 * Browser-based terminal access
 */

// Module metadata
$module = [
    'id' => 'terminal',
    'title' => 'Terminal',
    'icon' => 'terminal',
    'description' => 'Web-based terminal access',
    'category' => 'tools'
];

// If this is being included for metadata discovery, return early
if (!defined('EMBEDDED_MODULE')) {
    return;
}

?>
<style>
  #siabox-iframe {
    width:100%;
    height:80vh;
    border:2px solid #dee2e6;
    border-radius:4px;
  }
</style>

<h3 class="mb-3"><i class="bi bi-terminal me-2"></i>Terminal</h3>

<iframe id="siabox-iframe" src="" loading="lazy"></iframe>

<script>
(() => {
  const hostOrigin = 'https://192.168.8.1';
  const url = hostOrigin + ':4200';
  document.getElementById('siabox-iframe').src = url;
})();
</script> 