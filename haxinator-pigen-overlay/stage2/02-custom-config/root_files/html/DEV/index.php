<?php
/* index.php – Loads instantly with a Ping button. */
?><!DOCTYPE html>
<html lang="en">
<meta charset="utf-8">
<title>Live Ping Demo</title>
<style>
  body{font-family:system-ui,sans-serif;margin:2rem;}
  button{padding:.6rem 1.2rem;font-size:1rem;cursor:pointer;}
  pre{background:#111;color:#0f0;padding:1rem;height:18rem;
      overflow-y:auto;white-space:pre-wrap;border-radius:.4rem;}
  #status{margin-left:1rem;font-weight:bold;}
</style>

<h1>Live Ping Demo</h1>
<button id="runPing">Ping google.com</button><span id="status"></span>
<pre id="log">(output appears here)</pre>

<script>
let es;                                     // EventSource handle
const log    = document.getElementById('log');
const status = document.getElementById('status');

document.getElementById('runPing').onclick = () => {
  if (es) es.close();                      // close any previous stream
  log.textContent = '';                    // clear
  status.textContent = ' ⏳ Running…';

  es = new EventSource('ping-stream.php');

  es.onmessage = e => {                    // every line from server
    log.textContent += e.data + '\n';
    log.scrollTop = log.scrollHeight;
  };

  es.addEventListener('done', () => {      // custom “done” event
    status.textContent = ' ✅ Finished';
    es.close();
  });

  es.onerror = () => {                     // show connection errors
    status.textContent = ' ❌ Stream error';
    es.close();
  };
};
</script>
</html>
