<?php
usleep(isset($_GET['delay']) ? max(0, min(300, (int) $_GET['delay'])) * 1000 : 180000);
header('Content-Type: text/plain');
echo 'Raw stream wait: 180 ms';
