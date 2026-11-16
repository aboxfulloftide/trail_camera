<?php
declare(strict_types=1);

const MYSQL_FILE = __DIR__ . '/mysql';
const DEFAULT_MEDIA_DIR = __DIR__ . '/storage/media';
const DEFAULT_REGISTRATION_PATH = '/tmp/gardepro_board_registration.json';
const DEFAULT_CAMERA_SESSION_LOCK_PATH = '/tmp/trail_cam_camera_session.lock';
const DEFAULT_CAMERA_SESSION_STATE_PATH = '/tmp/trail_cam_camera_session.json';
const DEFAULT_API_CACHE_DIR = '/tmp/trail_cam_api_cache';
// Keep all media under one root. ESP32 uploads remain in storage/media while
// trail-camera downloads (and their thumbnails/onboard copies) live in the
// trail subdirectory so numeric filenames from the two sources cannot clash.
const DEFAULT_PICTURES_DIR = __DIR__ . '/storage/media/trail';
const DEFAULT_MEDIA_JOB_DIR = '/tmp/trail_cam_media_jobs';
const DEFAULT_DURABLE_MEDIA_JOB_STATE_PATH = '/tmp/trail_cam_durable_media_job.json';
const DEFAULT_TRAIL_MEDIA_PULL_LOCK_PATH = '/tmp/trail_cam_trail_media_pull.lock';

final class ApiError extends RuntimeException
{
    public function __construct(
        public string $errorCode,
        string $message,
        public int $httpStatus = 400,
        public mixed $details = null
    ) {
        parent::__construct($message);
    }
}

function json_response(mixed $data, int $status = 200, bool $envelope = true): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $envelope ? ['ok' => true, 'data' => $data, 'error' => null] : $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function error_response(ApiError $error): never
{
    http_response_code($error->httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'data' => null,
        'error' => [
            'code' => $error->errorCode,
            'message' => $error->getMessage(),
            'details' => $error->details,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function accepted_json_response_and_continue(mixed $data, callable $work): never
{
    ignore_user_abort(true);
    http_response_code(202);
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_encode([
        'ok' => true,
        'data' => $data,
        'error' => null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    header('Content-Length: ' . strlen($payload));
    echo $payload;

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    try {
        $work();
    } catch (Throwable) {
    }
    exit;
}

function accepted_json_response(mixed $data, int $status = 202): never
{
    json_response($data, $status);
}

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new ApiError('invalid_request', 'Request body must be a JSON object.');
    }
    return $decoded;
}

// Camera wakes can take well over PHP's default 30 s (a slow bring-up was 99 s).
if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/live/mjpeg')) {
    @set_time_limit(180);
}

function route_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base)) ?: '/';
    }
    return '/' . trim($path, '/');
}

function public_api_base(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(dirname($script), '/');
    if ($base === '' || $base === '/') {
        return '/api';
    }
    return $base . '/api';
}

function public_mount_base(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(dirname($script), '/');
    return ($base === '' || $base === '/') ? '' : $base;
}

function live_sdp_path(): string
{
    return getenv('GARDEPRO_SDP_PATH') ?: '/tmp/gardepro_live.sdp';
}

function live_player_ready(): bool
{
    return is_file(live_sdp_path());
}

function live_tunnel_listener_ready(): bool
{
    $socket = @stream_socket_client('tcp://127.0.0.1:6000', $errno, $error, 1);
    if ($socket === false) {
        return false;
    }
    fclose($socket);
    return true;
}

function live_snapshot_path(): string
{
    return getenv('GARDEPRO_LIVE_SNAPSHOT_PATH') ?: '/tmp/gardepro_live_preview.jpg';
}

function live_snapshot_lock_path(): string
{
    return getenv('GARDEPRO_LIVE_SNAPSHOT_LOCK_PATH') ?: '/tmp/gardepro_live_preview.lock';
}

function live_snapshot_ttl(): int
{
    return max(1, (int)(getenv('GARDEPRO_LIVE_SNAPSHOT_TTL') ?: 2));
}

function live_mjpeg_port(): int
{
    return max(1024, min(65535, (int)(getenv('GARDEPRO_LIVE_MJPEG_PORT') ?: 8091)));
}

function live_mjpeg_pid_path(): string
{
    return getenv('GARDEPRO_LIVE_MJPEG_PID_PATH') ?: '/tmp/gardepro_live_mjpeg.pid';
}

function live_mjpeg_log_path(): string
{
    return getenv('GARDEPRO_LIVE_MJPEG_LOG_PATH') ?: '/tmp/gardepro_live_mjpeg.log';
}

function live_mjpeg_progress_path(): string
{
    return getenv('GARDEPRO_LIVE_MJPEG_PROGRESS_PATH') ?: '/tmp/gardepro_live_mjpeg.progress';
}

function live_mjpeg_frame_path(): string
{
    return getenv('GARDEPRO_LIVE_MJPEG_FRAME_PATH') ?: '/tmp/gardepro_live_frame.jpg';
}

function live_mjpeg_frame_count(): ?int
{
    $progress = @file_get_contents(live_mjpeg_progress_path());
    if (!is_string($progress) || !preg_match_all('/^frame=(\d+)$/m', $progress, $matches) || empty($matches[1])) {
        return null;
    }
    return (int)end($matches[1]);
}

function live_mjpeg_process_running(): bool
{
    $pid = (int)trim((string)@file_get_contents(live_mjpeg_pid_path()));
    if ($pid <= 1) {
        return false;
    }
    if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
        return true;
    }
    if (is_dir('/proc/' . $pid)) {
        return true;
    }
    @unlink(live_mjpeg_pid_path());
    return false;
}

function start_live_mjpeg_relay(): bool
{
    if (live_mjpeg_process_running()) {
        return true;
    }
    if (!live_player_ready()) {
        return false;
    }
    $ffmpeg = '/usr/bin/ffmpeg';
    if (!is_executable($ffmpeg)) {
        throw new ApiError('live_relay_unavailable', 'ffmpeg is not available for the live relay.', 503);
    }
    $fps = max(1, min(12, (int)(getenv('GARDEPRO_LIVE_MJPEG_FPS') ?: 4)));
    $width = max(320, min(1920, (int)(getenv('GARDEPRO_LIVE_MJPEG_WIDTH') ?: 960)));
    $filter = 'fps=' . $fps . ',scale=' . $width . ':-2';
    @unlink(live_mjpeg_progress_path());
    @unlink(live_mjpeg_frame_path());
    $command = sprintf(
        'nohup %s -nostdin -hide_banner -loglevel warning -stats_period 1 -progress %s -protocol_whitelist file,udp,rtp -thread_queue_size 8192 -buffer_size 4194304 -reorder_queue_size 8192 -max_delay 1000000 -fflags +genpts -err_detect ignore_err -i %s -an -vf %s -q:v 5 -f image2 -update 1 %s > %s 2>&1 & echo $!',
        escapeshellarg($ffmpeg),
        escapeshellarg(live_mjpeg_progress_path()),
        escapeshellarg(live_sdp_path()),
        escapeshellarg($filter),
        escapeshellarg(live_mjpeg_frame_path()),
        escapeshellarg(live_mjpeg_log_path())
    );
    exec($command, $output, $exitCode);
    $pid = isset($output[0]) ? (int)trim($output[0]) : 0;
    if ($exitCode !== 0 || $pid <= 1) {
        throw new ApiError('live_relay_start_failed', 'Failed to start the MJPEG live relay.', 502, ['exit_code' => $exitCode]);
    }
    @file_put_contents(live_mjpeg_pid_path(), (string)$pid, LOCK_EX);
    return true;
}

function stop_live_mjpeg_relay(): void
{
    $pid = (int)trim((string)@file_get_contents(live_mjpeg_pid_path()));
    if ($pid > 1) {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15); // SIGTERM; pcntl's SIGTERM constant is not always defined in PHP-FPM.
            usleep(250000);
            if (@posix_kill($pid, 0)) {
                @posix_kill($pid, 9); // A blocked ffmpeg HTTP listener may ignore SIGTERM.
            }
        } else {
            exec('kill -TERM ' . (int)$pid . ' 2>/dev/null; sleep 1; kill -KILL ' . (int)$pid . ' 2>/dev/null');
        }
    }
    @unlink(live_mjpeg_pid_path());
}

function proxy_live_mjpeg(): never
{
    if (!live_mjpeg_process_running()) {
        throw new ApiError('live_relay_unavailable', 'Live relay is not running.', 503);
    }
    header('Content-Type: multipart/x-mixed-replace; boundary=trailcam');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Accel-Buffering: no');
    @set_time_limit(0);
    $lastFrame = '';
    $framePath = live_mjpeg_frame_path();
    while (!connection_aborted() && live_mjpeg_process_running()) {
        clearstatcache(true, $framePath);
        $mtime = @filemtime($framePath);
        $size = @filesize($framePath);
        $frameId = ($mtime ?: 0) . ':' . ($size ?: 0);
        if ($mtime && $size && $frameId !== $lastFrame) {
            $jpeg = @file_get_contents($framePath);
            if (is_string($jpeg) && $jpeg !== '') {
                echo "--trailcam\r\nContent-Type: image/jpeg\r\nContent-Length: " . strlen($jpeg) . "\r\n\r\n";
                echo $jpeg . "\r\n";
                flush();
                $lastFrame = $frameId;
            }
        }
        usleep(125000);
    }
    exit;
}

function live_snapshot_age(): int
{
    $path = live_snapshot_path();
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return PHP_INT_MAX;
    }
    $mtime = @filemtime($path);
    return $mtime ? max(0, time() - $mtime) : PHP_INT_MAX;
}

function live_snapshot_command(string $sourcePath, string $targetPath): string
{
    $timeout = max(3, (int)(getenv('GARDEPRO_LIVE_SNAPSHOT_TIMEOUT') ?: 8));
    $ffmpeg = escapeshellarg('/usr/bin/ffmpeg');
    $timeoutBin = escapeshellarg('/usr/bin/timeout');
    $source = escapeshellarg($sourcePath);
    $target = escapeshellarg($targetPath);
    return "{$timeoutBin} {$timeout}s {$ffmpeg} -hide_banner -loglevel error -y -protocol_whitelist file,udp,rtp -fflags nobuffer -flags low_delay -i {$source} -frames:v 1 -q:v 4 -update 1 {$target}";
}

function ensure_live_snapshot(): string
{
    if (!live_player_ready()) {
        throw new ApiError('live_receiver_unavailable', 'Live receiver not ready.', 503, [
            'sdp_path' => live_sdp_path(),
        ]);
    }

    $target = live_snapshot_path();
    if (is_file($target) && live_snapshot_age() < live_snapshot_ttl()) {
        return $target;
    }

    $lock = fopen(live_snapshot_lock_path(), 'c+');
    if ($lock === false) {
        throw new ApiError('internal_error', 'Failed to open live snapshot lock.', 500);
    }

    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_file($target)) {
                return $target;
            }
            throw new ApiError('live_snapshot_busy', 'Live preview is currently rendering.', 503);
        }

        clearstatcache(true, $target);
        if (is_file($target) && live_snapshot_age() < live_snapshot_ttl()) {
            return $target;
        }

        @unlink($target);
        $command = live_snapshot_command(live_sdp_path(), $target);
        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($target) || filesize($target) <= 0) {
            @unlink($target);
            throw new ApiError('live_snapshot_failed', 'Failed to render live preview frame.', 503, [
                'exit_code' => $exitCode,
                'command' => 'ffmpeg snapshot',
                'details' => array_slice($output, 0, 10),
            ]);
        }
        return $target;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function mysql_config(): array
{
    if (!is_file(MYSQL_FILE)) {
        throw new ApiError('internal_error', 'MySQL credential file is missing.', 500);
    }
    $config = [];
    foreach (file(MYSQL_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $config[trim($key)] = trim($value);
    }
    return [
        'host' => $config['host'] ?? '127.0.0.1',
        'user' => $config['user'] ?? '',
        'pass' => $config['pass'] ?? '',
        'db' => $config['trailcamdb'] ?? $config['db'] ?? 'trail',
    ];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = mysql_config();
    try {
        $pdo = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['db']};charset=utf8mb4",
            $cfg['user'],
            $cfg['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (PDOException $e) {
        throw new ApiError('internal_error', 'Database connection failed.', 500, $e->getMessage());
    }
    install_schema($pdo);
    return $pdo;
}

function ai_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = mysql_config();
    $pdo = new PDO("mysql:host={$cfg['host']};dbname=trailcam_ai;charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}

function media_protection_ids(): array
{
    db()->exec('CREATE TABLE IF NOT EXISTS media_protection (ai_media_id BIGINT UNSIGNED PRIMARY KEY, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    return array_fill_keys(array_map('intval', db()->query('SELECT ai_media_id FROM media_protection')->fetchAll(PDO::FETCH_COLUMN)), true);
}

function set_media_protection(int $id, bool $keep): void
{
    media_protection_ids();
    if ($keep) db()->prepare('INSERT IGNORE INTO media_protection (ai_media_id) VALUES (?)')->execute([$id]);
    else db()->prepare('DELETE FROM media_protection WHERE ai_media_id=?')->execute([$id]);
}

function ai_media_items(): array
{
    $items = ai_db()->query("SELECT m.id, m.filename, m.path, m.media_type, m.size_bytes, c.capture_time, a.status, a.animal_confidence, a.processed_at,
        s.species, s.confidence AS species_confidence, s.crop_box
        FROM media_files m
        LEFT JOIN capture_events c ON c.id=m.capture_event_id
        LEFT JOIN analysis_results a ON a.id=(SELECT ar.id FROM analysis_results ar WHERE ar.media_id=m.id ORDER BY ar.processed_at DESC, ar.id DESC LIMIT 1)
        LEFT JOIN species_classifications s ON s.id=(SELECT sc.id FROM species_classifications sc WHERE sc.media_id=m.id AND sc.status='ok' ORDER BY sc.processed_at DESC, sc.id DESC LIMIT 1)
        ORDER BY COALESCE(c.capture_time, m.last_seen_at) DESC, m.id DESC")->fetchAll();
    $cachePath = sys_get_temp_dir() . '/trailcam_ai_capture_metadata.json';
    $cache = is_file($cachePath) ? (json_decode(file_get_contents($cachePath) ?: '', true) ?: []) : [];
    $changed = false;
    foreach ($items as &$item) {
        $path = ai_local_media_path((string)$item['path']);
        if (!$path) continue;
        $key = $path . ':' . (string)(filemtime($path) ?: 0);
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = camera_media_capture_timestamp($path, strtolower((string)pathinfo($path, PATHINFO_EXTENSION)));
            $changed = true;
        }
        $item['metadata_capture_time'] = $cache[$key] ?: null;
    }
    unset($item);
    if ($changed) @file_put_contents($cachePath, json_encode($cache), LOCK_EX);
    return $items;
}

function ai_local_media_path(string $path): ?string
{
    if (!str_starts_with($path, '/mnt/trail-media/')) return null;
    $real = realpath(DEFAULT_MEDIA_DIR . '/' . substr($path, strlen('/mnt/trail-media/'))); $root = realpath(DEFAULT_MEDIA_DIR);
    return $real && $root && str_starts_with($real, rtrim($root, '/') . '/') && is_file($real) ? $real : null;
}

function ai_capture_time(array $item): string
{
    if (!empty($item['metadata_capture_time'])) return (string)$item['metadata_capture_time'];
    if (!empty($item['capture_time'])) return (string)$item['capture_time'];
    if (preg_match('/trailcam_(\d{14})_/', (string)($item['filename'] ?? ''), $m)) {
        return substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2) . ' ' . substr($m[1], 8, 2) . ':' . substr($m[1], 10, 2) . ':' . substr($m[1], 12, 2);
    }
    return '-';
}

function render_ai_media(): never
{
    header('Content-Type: text/html; charset=utf-8');
    $base = htmlspecialchars(public_mount_base(), ENT_QUOTES, 'UTF-8');
    $analysisStatuses = ai_db()->query("SELECT DISTINCT status FROM analysis_results WHERE status IS NOT NULL AND status <> '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
    $analysisJson = json_encode(array_values($analysisStatuses), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $rows = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', () => {
  const headerKeep = document.querySelector('thead tr th:last-child');
  if (headerKeep) headerKeep.insertAdjacentHTML('beforebegin', '<th>Species</th>');
  document.querySelectorAll('tbody tr[data-analysis]').forEach(row => {
    const cell = document.createElement('td');
    cell.className = 'species-cell';
    cell.textContent = row.dataset.species || '-';
    row.lastElementChild.before(cell);
  });

  const controls = document.querySelector('.button-row.compact');
  const speciesFilter = document.createElement('select');
  speciesFilter.id = 'speciesFilter';
  speciesFilter.append(new Option('All species', ''));
  speciesFilter.append(new Option('Blank', '__blank__'));
  speciesFilter.append(new Option('Blank by image', '__blank_by_image__'));
  [...new Set([...document.querySelectorAll('tbody tr[data-species-name]')]
    .map(row => row.dataset.speciesName)
    .filter(Boolean))]
    .sort((a, b) => a.localeCompare(b))
    .forEach(species => speciesFilter.append(new Option(species, species)));
  const speciesLabel = document.createElement('label');
  speciesLabel.append('Species ', speciesFilter);
  controls?.insertBefore(speciesLabel, document.getElementById('deleteSelected'));
  const filterStorageKey = 'trail-media-filters';
  let savedFilters = {};
  try { savedFilters = JSON.parse(sessionStorage.getItem(filterStorageKey) || '{}') || {}; } catch (_) {}
  const analysisFilter = document.getElementById('analysisFilter');
  const typeFilter = document.getElementById('typeFilter');
  if ([...analysisFilter.options].some(option => option.value === savedFilters.analysis)) analysisFilter.value = savedFilters.analysis;
  if ([...typeFilter.options].some(option => option.value === savedFilters.type)) typeFilter.value = savedFilters.type;
  if ([...speciesFilter.options].some(option => option.value === savedFilters.species)) speciesFilter.value = savedFilters.species;
  const toggle = document.createElement('button');
  toggle.id = 'cropBoxesToggle';
  toggle.type = 'button';
  toggle.textContent = 'Show crop boxes';
  toggle.setAttribute('aria-pressed', 'false');
  controls?.insertBefore(toggle, document.getElementById('refreshMedia'));

  const applySpeciesFilter = () => {
    document.querySelectorAll('tbody tr[data-analysis]').forEach(row => {
      row.hidden = Boolean(
        (analysisFilter.value && row.dataset.analysis !== analysisFilter.value)
        || (typeFilter.value && row.dataset.type !== typeFilter.value)
        || (speciesFilter.value === '__blank__' && row.dataset.analysis !== 'blank' && row.dataset.speciesName !== 'no-species')
        || (speciesFilter.value === '__blank_by_image__' && row.dataset.analysis !== 'blank_by_image')
        || (speciesFilter.value && !speciesFilter.value.startsWith('__') && row.dataset.speciesName !== speciesFilter.value)
      );
    });
    const rows = [...document.querySelectorAll('tbody tr[data-analysis]')];
    document.getElementById('displayCount').textContent = `(${rows.filter(row => !row.hidden).length} of ${rows.length} files)`;
  };
  speciesFilter.addEventListener('change', applySpeciesFilter);
  analysisFilter.addEventListener('change', applySpeciesFilter);
  typeFilter.addEventListener('change', applySpeciesFilter);
  const saveFilters = () => sessionStorage.setItem(filterStorageKey, JSON.stringify({
    analysis: analysisFilter.value,
    type: typeFilter.value,
    species: speciesFilter.value,
  }));
  speciesFilter.addEventListener('change', saveFilters);
  analysisFilter.addEventListener('change', saveFilters);
  typeFilter.addEventListener('change', saveFilters);
  const clearFilters = document.createElement('button');
  clearFilters.type = 'button';
  clearFilters.textContent = 'Clear filters';
  controls?.insertBefore(clearFilters, document.getElementById('deleteSelected'));
  clearFilters.addEventListener('click', () => {
    analysisFilter.value = '';
    typeFilter.value = '';
    speciesFilter.value = '';
    sessionStorage.removeItem(filterStorageKey);
    applySpeciesFilter();
  });
  applySpeciesFilter();

  let showBoxes = false;
  let active = null;
  const renderBoxes = () => {
    if (!active) return;
    active.frame.querySelectorAll('.crop-box').forEach(box => box.remove());
    if (!showBoxes || !active.crop || !active.image.naturalWidth || !active.image.naturalHeight) return;
    const [x1, y1, x2, y2] = active.crop.map(Number);
    if (![x1, y1, x2, y2].every(Number.isFinite) || x2 <= x1 || y2 <= y1) return;
    const box = document.createElement('div');
    box.className = 'crop-box';
    box.style.left = `${(x1 / active.image.naturalWidth) * 100}%`;
    box.style.top = `${(y1 / active.image.naturalHeight) * 100}%`;
    box.style.width = `${((x2 - x1) / active.image.naturalWidth) * 100}%`;
    box.style.height = `${((y2 - y1) / active.image.naturalHeight) * 100}%`;
    active.frame.append(box);
  };
  toggle.addEventListener('click', () => {
    showBoxes = !showBoxes;
    toggle.textContent = showBoxes ? 'Hide crop boxes' : 'Show crop boxes';
    toggle.setAttribute('aria-pressed', String(showBoxes));
    renderBoxes();
  });

  // Capture this click before the standard lightbox handler so images can include the optional overlay.
  document.addEventListener('click', event => {
    const link = event.target.closest('.ai-open');
    if (!link) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const out = document.getElementById('aiLightboxMedia');
    const lightbox = document.getElementById('aiLightbox');
    out.replaceChildren();
    active = null;
    if (link.dataset.type === 'video') {
      const video = document.createElement('video');
      video.src = link.href;
      video.controls = true;
      video.volume = 0.2;
      out.append(video);
    } else {
      let crop = null;
      try { crop = JSON.parse(link.closest('tr').dataset.cropBox || 'null'); } catch (_) {}
      const frame = document.createElement('div');
      frame.className = 'lightbox-media-frame';
      const image = document.createElement('img');
      image.src = link.href;
      frame.append(image);
      out.append(frame);
      active = { frame, image, crop };
      image.addEventListener('load', renderBoxes, { once: true });
    }
    const species = link.closest('tr').dataset.species;
    document.getElementById('aiLightboxTime').textContent = `Captured: ${link.dataset.time}${species && species !== '-' ? ` · Species: ${species}` : ''}`;
    lightbox.hidden = false;
  }, true);
});
</script>
HTML;
    foreach (ai_media_items() as $item) {
        $status = htmlspecialchars((string)($item['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars((string)$item['filename'], ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars((string)$item['media_type'], ENT_QUOTES, 'UTF-8');
        $captured = htmlspecialchars(ai_capture_time($item), ENT_QUOTES, 'UTF-8');
        $processed = htmlspecialchars((string)($item['processed_at'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $confidence = $item['animal_confidence'] === null ? '-' : number_format((float)$item['animal_confidence'] * 100, 1) . '%';
        $id = (int)$item['id'];
        $isClassifiedAnimal = ($item['status'] ?? '') === 'animal';
        $speciesName = $isClassifiedAnimal && !empty($item['species']) ? (string)$item['species'] : '';
        $species = $speciesName !== ''
            ? $speciesName . ($item['species_confidence'] !== null ? ' (' . number_format((float)$item['species_confidence'] * 100, 1) . '%)' : '')
            : '-';
        $species = htmlspecialchars($species, ENT_QUOTES, 'UTF-8');
        $speciesName = htmlspecialchars($speciesName, ENT_QUOTES, 'UTF-8');
        $cropBox = $isClassifiedAnimal && ($item['media_type'] ?? '') === 'image' ? json_decode((string)($item['crop_box'] ?? ''), true) : null;
        $cropBox = is_array($cropBox) && count($cropBox) === 4 && count(array_filter($cropBox, 'is_numeric')) === 4 ? array_values($cropBox) : null;
        $cropBoxJson = htmlspecialchars(json_encode($cropBox), ENT_QUOTES, 'UTF-8');
        $thumb = $base . '/api/ai-media/' . $id . '/thumb';
        $rows .= "<tr data-analysis=\"{$status}\" data-captured=\"{$captured}\" data-type=\"{$type}\" data-species=\"{$species}\" data-species-name=\"{$speciesName}\" data-crop-box=\"{$cropBoxJson}\"><td><input class=\"ai-select\" type=\"checkbox\" value=\"{$id}\" autocomplete=\"off\"></td><td><a class=\"ai-open\" data-type=\"{$type}\" data-time=\"{$captured}\" href=\"{$base}/api/ai-media/{$id}/file\"><img class=\"thumb\" src=\"{$thumb}\" alt=\"{$file}\"></a></td><td>{$file}</td><td>{$type}</td><td>{$captured}</td><td>{$status}</td><td>{$confidence}</td><td>{$processed}</td><td><input class=\"keep-media\" type=\"checkbox\" autocomplete=\"off\" title=\"Keep this file\"></td></tr>";
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trail Media</title><link rel="stylesheet" href="' . $base . '/app.css"></head><body><main class="app"><header class="topbar"><div><div class="eyebrow">Trail Camera</div><h1>Media analysis</h1></div><div class="top-actions"><nav class="view-nav"><a href="' . $base . '/trail">Trail Camera</a><a href="' . $base . '/media" class="active">Media</a><a href="' . $base . '/onboard">Onboard Camera</a></nav><button id="darkMode"></button></div></header><section class="wide-section"><div class="section-head"><h2>Detected media <small id="displayCount"></small></h2><div class="button-row compact"><label>Filter <select id="analysisFilter" autocomplete="off"></select></label><label>Type <select id="typeFilter" autocomplete="off"><option value="">All types</option><option value="image">Images</option><option value="video">Videos</option></select></label><button id="deleteSelected" disabled>Delete selected</button><button id="refreshMedia">Refresh</button></div></div><div class="media-wrap"><table class="media-table"><thead><tr><th><input id="selectAllAi" type="checkbox" autocomplete="off" title="Select visible"></th><th>Preview</th><th>File</th><th>Type</th><th><button id="captureSort" class="text-button" title="Sort by capture time">Captured ↓</button></th><th>Analysis</th><th>Confidence</th><th>Processed</th><th>Keep</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section></main><div id="aiLightbox" class="lightbox" hidden><div class="lightbox-backdrop"></div><div class="lightbox-content"><button class="lightbox-close">&times;</button><div id="aiLightboxMedia"></div><p id="aiLightboxTime"></p></div></div><script>(()=>{const base=' . json_encode($base) . ',statuses=' . $analysisJson . ',q=s=>document.querySelectorAll(s),filter=document.getElementById("analysisFilter"),type=document.getElementById("typeFilter"),del=document.getElementById("deleteSelected"),all=document.getElementById("selectAllAi"),box=document.getElementById("aiLightbox"),out=document.getElementById("aiLightboxMedia");filter.replaceChildren(new Option("All",""),...statuses.map(s=>new Option(s.replaceAll("_"," "),s)));const clearSelection=()=>{q(".ai-select").forEach(x=>x.checked=false);all.checked=false;del.disabled=true;};const sync=()=>del.disabled=!q(".ai-select:checked").length;const apply=()=>{q("tbody tr").forEach(r=>r.hidden=!!((filter.value&&r.dataset.analysis!==filter.value)||(type.value&&r.dataset.type!==type.value)));all.checked=false;sync();};clearSelection();window.addEventListener("pageshow",clearSelection);filter.onchange=apply;type.onchange=apply;q(".ai-select").forEach(x=>x.onchange=sync);all.onchange=()=>{q("tbody tr:not([hidden]) .ai-select:not(:disabled)").forEach(x=>x.checked=all.checked);sync();};document.getElementById("refreshMedia").onclick=()=>location.reload();let desc=true;document.getElementById("captureSort").onclick=e=>{const body=document.querySelector("tbody"),rows=[...body.rows];desc=!desc;rows.sort((a,b)=>a.dataset.captured.localeCompare(b.dataset.captured)*(desc?-1:1)).forEach(r=>body.append(r));e.target.textContent=`Captured ${desc?"↓":"↑"}`;};q(".ai-open").forEach(a=>a.onclick=e=>{e.preventDefault();out.innerHTML="";const m=document.createElement(a.dataset.type==="video"?"video":"img");m.src=a.href;if(m.tagName==="VIDEO"){m.controls=true;m.volume=.2;}out.append(m);document.getElementById("aiLightboxTime").textContent="Captured: "+a.dataset.time;box.hidden=false;});const close=()=>{out.innerHTML="";box.hidden=true;};box.querySelector(".lightbox-close").onclick=close;box.querySelector(".lightbox-backdrop").onclick=close;del.onclick=async()=>{const ids=[...q(".ai-select:checked")].map(x=>+x.value);if(!ids.length||!confirm(`Permanently delete ${ids.length} selected file(s) and their analysis records?`))return;const r=await fetch(base+"/api/ai-media/delete-selected",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({confirm:"delete-selected",ids})});if(!r.ok)alert("Delete failed");else location.reload();};fetch(base+"/api/media-protection").then(r=>r.json()).then(data=>{const kept=new Set((data.data?.ids||[]).map(String));q("tbody tr").forEach(row=>{const select=row.querySelector(".ai-select"),keep=row.querySelector(".keep-media"),saved=kept.has(select.value);keep.checked=saved;select.disabled=saved;keep.onchange=async()=>{const want=keep.checked;const r=await fetch(base+"/api/media-protection",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:+select.value,keep:want})});if(!r.ok){keep.checked=!want;return;}select.disabled=want;if(want)select.checked=false;sync();};});}).catch(()=>{});const dark=document.body.classList.toggle("dark-mode",localStorage.getItem("trail-media-dark")==="1"),darkButton=document.getElementById("darkMode");darkButton.textContent=dark?"Light mode":"Dark mode";darkButton.onclick=()=>{const isDark=document.body.classList.toggle("dark-mode");localStorage.setItem("trail-media-dark",isDark?"1":"0");darkButton.textContent=isDark?"Light mode":"Dark mode";};})();</script></body></html>'; exit;
}

function delete_ai_media(array $ids): array
{
    $pdo = ai_db(); $protected = media_protection_ids(); $deleted=[]; $failed=[];
    $ids = array_values(array_filter($ids, fn($id) => !isset($protected[(int)$id])));
    foreach ($ids as $id) { $id=(int)$id; $path=ai_media_file_path($id); if (!$path || !@unlink($path)) { $failed[]=$id; continue; }
        $pdo->beginTransaction(); try { $pdo->prepare('DELETE FROM analysis_results WHERE media_id=?')->execute([$id]); $pdo->prepare('DELETE FROM media_files WHERE id=?')->execute([$id]); $pdo->commit(); db()->prepare('DELETE FROM media_protection WHERE ai_media_id=?')->execute([$id]); $deleted[]=$id; } catch (Throwable $e) { $pdo->rollBack(); $failed[]=$id; }
    } return ['deleted'=>$deleted,'failed'=>$failed];
}

function ai_media_file_path(int $id): ?string
{
    $stmt = ai_db()->prepare('SELECT path FROM media_files WHERE id=?'); $stmt->execute([$id]); $path = $stmt->fetchColumn();
    return is_string($path) ? ai_local_media_path($path) : null;
}

function proxy_ai_media(int $id, bool $thumb): never
{
    $path = ai_media_file_path($id); if (!$path) throw new ApiError('not_found', 'AI media file was not found locally.', 404);
    $video = str_ends_with(strtolower($path), '.mp4');
    if ($thumb && $video) { $generated = generate_video_thumb($id, $path); if (!$generated) throw new ApiError('thumbnail_failed', 'Video thumbnail generation failed.', 500); header('Content-Type: image/jpeg'); readfile($generated); exit; }
    header('Content-Type: ' . ($thumb ? 'image/jpeg' : ($video ? 'video/mp4' : 'image/jpeg'))); readfile($path); exit;
}

function install_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rf_observations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scanner_host VARCHAR(128) NOT NULL,
            scanner_mac VARCHAR(64) NULL,
            scanner_health JSON NULL,
            mac VARCHAR(64) NOT NULL,
            device_type VARCHAR(32) NOT NULL,
            interface_name VARCHAR(64) NULL,
            signal_dbm INT NULL,
            channel INT NULL,
            freq_mhz INT NULL,
            ssid VARCHAR(255) NULL,
            ht TINYINT(1) NULL,
            vht TINYINT(1) NULL,
            he TINYINT(1) NULL,
            probe_count INT NULL,
            manufacturer_data TEXT NULL,
            adv_services TEXT NULL,
            adv_service_data TEXT NULL,
            tx_power INT NULL,
            tracker_type VARCHAR(128) NULL,
            recorded_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rf_host_time (scanner_host, recorded_at),
            INDEX idx_rf_mac_time (mac, recorded_at),
            INDEX idx_rf_type (device_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS board_telemetry (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scanner_host VARCHAR(128) NOT NULL,
            recorded_at DATETIME NOT NULL,
            firmware JSON NULL,
            board JSON NULL,
            battery JSON NULL,
            temperature JSON NULL,
            radio JSON NULL,
            payload JSON NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_telemetry_host_time (scanner_host, recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS board_media (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scanner_host VARCHAR(128) NOT NULL,
            source ENUM('onboard','trail_camera') NOT NULL,
            media_type VARCHAR(64) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            recorded_at DATETIME NOT NULL,
            expected_bytes BIGINT UNSIGNED NULL,
            sha256 CHAR(64) NULL,
            metadata JSON NULL,
            original_media_id VARCHAR(128) NULL,
            original_media_type VARCHAR(64) NULL,
            camera_path VARCHAR(512) NULL,
            capture_kind VARCHAR(64) NULL,
            blob_path VARCHAR(512) NULL,
            blob_bytes BIGINT UNSIGNED NULL,
            blob_sha256 CHAR(64) NULL,
            uploaded_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_media_retry (scanner_host, source, recorded_at, sha256),
            INDEX idx_media_host_time (scanner_host, recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS board_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scanner_host VARCHAR(128) NOT NULL,
            recorded_at DATETIME NOT NULL,
            event_type VARCHAR(128) NOT NULL,
            ok TINYINT(1) NULL,
            reason VARCHAR(255) NULL,
            details JSON NULL,
            payload JSON NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_events_host_time (scanner_host, recorded_at),
            INDEX idx_events_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS board_status_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scanner_host VARCHAR(128) NOT NULL,
            recorded_at DATETIME NOT NULL,
            status_hash CHAR(64) NOT NULL,
            halow_connected TINYINT(1) NULL,
            wifi_connected TINYINT(1) NULL,
            stream_active TINYINT(1) NULL,
            tunnel_connected TINYINT(1) NULL,
            ble_stage VARCHAR(64) NULL,
            control_last_message VARCHAR(255) NULL,
            battery JSON NULL,
            camera_session JSON NULL,
            timing JSON NULL,
            payload JSON NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_board_status_time (recorded_at),
            INDEX idx_board_status_host_time (scanner_host, recorded_at),
            INDEX idx_board_status_hash_time (status_hash, recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS camera_connections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scanner_host VARCHAR(128) NOT NULL,
            camera VARCHAR(64) NOT NULL,
            job_id VARCHAR(128) NULL,

            started_at DATETIME NOT NULL,
            connected_at DATETIME NULL,
            ended_at DATETIME NULL,

            ok TINYINT(1) NOT NULL,
            failure_stage VARCHAR(64) NULL,
            failure_message VARCHAR(255) NULL,

            wake_method VARCHAR(32) NULL,
            wake_attempts SMALLINT UNSIGNED NULL,

            ble_rssi_dbm SMALLINT NULL,
            wifi_rssi_dbm SMALLINT NULL,
            wifi_rssi_min SMALLINT NULL,
            wifi_rssi_avg DECIMAL(5,1) NULL,
            wifi_rssi_max SMALLINT NULL,
            wifi_freq_mhz SMALLINT UNSIGNED NULL,
            wifi_tx_bitrate VARCHAR(64) NULL,
            wifi_rx_bitrate VARCHAR(64) NULL,

            ble_scan_ms INT UNSIGNED NULL,
            ble_connect_ms INT UNSIGNED NULL,
            hotspot_wait_ms INT UNSIGNED NULL,
            wifi_join_ms INT UNSIGNED NULL,
            total_connect_ms INT UNSIGNED NULL,

            link_losses SMALLINT UNSIGNED NULL,
            items_downloaded INT UNSIGNED NULL,
            bytes_downloaded BIGINT UNSIGNED NULL,
            avg_kbps DECIMAL(10,1) NULL,

            details JSON NULL,
            payload JSON NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uniq_camconn_attempt (scanner_host, camera, started_at),
            INDEX idx_camconn_time (started_at),
            INDEX idx_camconn_camera_time (camera, started_at),
            INDEX idx_camconn_ok (ok, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function sql_datetime(?string $value): string
{
    if (!$value) {
        return gmdate('Y-m-d H:i:s');
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Exception) {
        throw new ApiError('invalid_request', "Invalid timestamp: {$value}");
    }
}

function json_col(mixed $value): string
{
    return json_encode($value ?? new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function json_decode_array(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || $value === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function first_numeric(array $source, array $keys): ?float
{
    foreach ($keys as $key) {
        if (isset($source[$key]) && is_numeric($source[$key])) {
            return (float)$source[$key];
        }
    }
    return null;
}

function bridge_host(): string
{
    $host = getenv('GARDEPRO_BRIDGE_HOST') ?: '';
    if ($host !== '') {
        return $host;
    }
    $registrationPath = getenv('GARDEPRO_REGISTRATION_PATH') ?: DEFAULT_REGISTRATION_PATH;
    if (is_file($registrationPath)) {
        $registration = json_decode(file_get_contents($registrationPath) ?: '', true);
        if (is_array($registration) && !empty($registration['halow_ip'])) {
            return (string)$registration['halow_ip'];
        }
    }
    throw new ApiError('bridge_unreachable', 'Bridge host is not configured and no desktop-bridge registration is available.', 503);
}

function camera_session_lock_path(): string
{
    return getenv('TRAILCAM_CAMERA_SESSION_LOCK_PATH') ?: DEFAULT_CAMERA_SESSION_LOCK_PATH;
}

function camera_session_state_path(): string
{
    return getenv('TRAILCAM_CAMERA_SESSION_STATE_PATH') ?: DEFAULT_CAMERA_SESSION_STATE_PATH;
}

function camera_session_ttl(): int
{
    return max(5, (int)(getenv('TRAILCAM_CAMERA_SESSION_TTL') ?: 60));
}

function media_job_dir(): string
{
    return getenv('TRAILCAM_MEDIA_JOB_DIR') ?: DEFAULT_MEDIA_JOB_DIR;
}

function pictures_dir(): string
{
    return getenv('TRAILCAM_PICTURES_DIR') ?: DEFAULT_PICTURES_DIR;
}

function board_status_store_interval(): int
{
    return max(5, (int)(getenv('TRAILCAM_BOARD_STATUS_STORE_INTERVAL') ?: 30));
}

function api_cache_dir(): string
{
    return getenv('TRAILCAM_API_CACHE_DIR') ?: DEFAULT_API_CACHE_DIR;
}

function api_cache_ttl(string $bucket): int
{
    return match ($bucket) {
        'status' => max(1, (int)(getenv('TRAILCAM_STATUS_CACHE_TTL') ?: 1)),
        'settings' => max(1, (int)(getenv('TRAILCAM_SETTINGS_CACHE_TTL') ?: 15)),
        'media' => max(1, (int)(getenv('TRAILCAM_MEDIA_CACHE_TTL') ?: 10)),
        'clock' => max(1, (int)(getenv('TRAILCAM_CLOCK_CACHE_TTL') ?: 5)),
        default => max(1, (int)(getenv('TRAILCAM_DEFAULT_CACHE_TTL') ?: 5)),
    };
}

function ensure_api_cache_dir(): string
{
    $dir = rtrim(api_cache_dir(), '/');
    if ($dir === '') {
        throw new ApiError('internal_error', 'API cache directory path is empty.', 500);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new ApiError('internal_error', 'Failed to create API cache directory.', 500, $dir);
    }
    if (!is_writable($dir)) {
        throw new ApiError('internal_error', 'API cache directory is not writable.', 500, $dir);
    }
    return $dir;
}

function api_cache_lock_path(string $key): string
{
    return ensure_api_cache_dir() . '/' . sha1($key) . '.lock';
}

function api_cache_path(string $key): string
{
    return ensure_api_cache_dir() . '/' . sha1($key) . '.json';
}

function read_api_cache(string $key): mixed
{
    $path = api_cache_path($key);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode(file_get_contents($path) ?: '', true);
    if (!is_array($decoded)) {
        @unlink($path);
        return null;
    }
    $expiresAt = (int)($decoded['expires_at'] ?? 0);
    if ($expiresAt <= time()) {
        @unlink($path);
        return null;
    }
    return $decoded['value'] ?? null;
}

function write_api_cache(string $key, mixed $value, int $ttl): mixed
{
    $path = api_cache_path($key);
    $payload = [
        'expires_at' => time() + max(1, $ttl),
        'value' => $value,
    ];
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $value;
}

function invalidate_api_cache(string ...$keys): void
{
    foreach ($keys as $key) {
        $path = api_cache_path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function clear_api_cache(): void
{
    $dir = ensure_api_cache_dir();
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        @unlink($path);
    }
}

function remember_api_cache(string $bucket, string $key, callable $resolver): mixed
{
    $cached = read_api_cache($key);
    if ($cached !== null) {
        return $cached;
    }

    $lockHandle = fopen(api_cache_lock_path($key), 'c+');
    if ($lockHandle === false) {
        return write_api_cache($key, $resolver(), api_cache_ttl($bucket));
    }

    if (!flock($lockHandle, LOCK_EX)) {
        fclose($lockHandle);
        return write_api_cache($key, $resolver(), api_cache_ttl($bucket));
    }

    try {
        $cached = read_api_cache($key);
        if ($cached !== null) {
            return $cached;
        }
        return write_api_cache($key, $resolver(), api_cache_ttl($bucket));
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function ensure_media_job_dir(): string
{
    $dir = rtrim(media_job_dir(), '/');
    if ($dir === '') {
        throw new ApiError('internal_error', 'Media job directory path is empty.', 500);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new ApiError('internal_error', 'Failed to create media job directory.', 500, $dir);
    }
    if (!is_writable($dir)) {
        throw new ApiError('internal_error', 'Media job directory is not writable.', 500, $dir);
    }
    return $dir;
}

function media_job_path(string $jobId): string
{
    return ensure_media_job_dir() . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId) . '.json';
}

function read_media_job(string $jobId): ?array
{
    $path = media_job_path($jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode(file_get_contents($path) ?: '', true);
    if (!is_array($decoded)) {
        return null;
    }
    if (media_job_is_stale($decoded)) {
        fail_stale_media_job($decoded);
        return read_media_job($jobId);
    }
    return $decoded;
}

function write_media_job(string $jobId, array $job): array
{
    $job['job_id'] = $jobId;
    $job['updated_at'] = gmdate('c');
    @file_put_contents(media_job_path($jobId), json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $job;
}

function create_media_job(string $type, array $payload = []): array
{
    $jobId = bin2hex(random_bytes(8));
    $job = [
        'job_id' => $jobId,
        'type' => $type,
        'status' => 'pending',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'pid' => null,
        'payload' => $payload,
        'result' => null,
        'error' => null,
        'progress' => null,
    ];
    return write_media_job($jobId, $job);
}

function media_job_process_alive(array $job): bool
{
    $pid = (int)($job['pid'] ?? 0);
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    $output = [];
    $exitCode = 1;
    @exec('ps -p ' . $pid . ' -o pid=', $output, $exitCode);
    return trim(implode("\n", $output)) !== '';
}

function media_job_age_seconds(array $job): ?int
{
    $stamp = strtotime((string)($job['updated_at'] ?? $job['created_at'] ?? ''));
    if ($stamp === false) {
        return null;
    }
    return max(0, time() - $stamp);
}

function media_job_is_stale(array $job): bool
{
    $status = (string)($job['status'] ?? '');
    if (!in_array($status, ['pending', 'starting', 'running'], true)) {
        return false;
    }
    $age = media_job_age_seconds($job);
    if ($age === null) {
        return false;
    }
    $alive = media_job_process_alive($job);
    if ($status === 'pending' || $status === 'starting') {
        return $age > 5 && !$alive;
    }
    if ($status === 'running') {
        if (!$alive) {
            return $age > 15;
        }
        return $age > 300;
    }
    return false;
}

function fail_stale_media_job(array $job): void
{
    $message = 'Media job stalled before it started.';
    if (media_job_process_alive($job)) {
        $message = 'Media job process became unresponsive.';
    }
    write_media_job((string)$job['job_id'], array_merge($job, [
        'status' => 'failed',
        'result' => null,
        'error' => [
            'message' => $message,
            'details' => [
                'stale' => true,
                'age_seconds' => media_job_age_seconds($job),
                'pid' => $job['pid'] ?? null,
            ],
        ],
        'progress' => array_merge($job['progress'] ?? [], ['stage' => 'failed']),
    ]));
}

function active_media_job(string $type): ?array
{
    $jobs = glob(ensure_media_job_dir() . '/*.json') ?: [];
    sort($jobs);
    foreach ($jobs as $path) {
        $decoded = json_decode(file_get_contents($path) ?: '', true);
        if (!is_array($decoded)) {
            continue;
        }
        if (($decoded['type'] ?? '') !== $type) {
            continue;
        }
        if (media_job_is_stale($decoded)) {
            fail_stale_media_job($decoded);
            continue;
        }
        if (in_array(($decoded['status'] ?? ''), ['pending', 'running'], true)) {
            return $decoded;
        }
    }
    return null;
}

function start_media_job_process(string $jobId, string $command): void
{
    $job = read_media_job($jobId);
    if (!$job) {
        return;
    }
    write_media_job($jobId, array_merge($job, [
        'status' => 'starting',
        'pid' => null,
        'error' => null,
    ]));
    $cliPhp = PHP_BINDIR . '/php';
    if (!is_file($cliPhp) || !is_executable($cliPhp)) {
        $cliPhp = PHP_BINARY;
    }
    $php = escapeshellarg($cliPhp);
    $script = escapeshellarg(__FILE__);
    $jobArg = escapeshellarg($jobId);
    $logPath = escapeshellarg(ensure_media_job_dir() . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId) . '.log');
    $cmd = "nohup {$php} {$script} {$command} {$jobArg} >> {$logPath} 2>&1 < /dev/null & echo $!";
    $output = [];
    $exitCode = 1;
    exec($cmd, $output, $exitCode);
    $pid = isset($output[0]) ? (int)trim((string)$output[0]) : 0;
    if ($pid <= 0) {
        write_media_job($jobId, array_merge($job, [
            'status' => 'failed',
            'result' => null,
            'error' => [
                'message' => 'Failed to start media job worker.',
                'details' => [
                    'command' => $command,
                ],
            ],
        ]));
        return;
    }
    $current = read_media_job($jobId) ?: $job;
    if (in_array(($current['status'] ?? ''), ['pending', 'starting'], true)) {
        write_media_job($jobId, array_merge($current, [
            'pid' => $pid,
        ]));
    }
}

function read_camera_session_state(): array
{
    $path = camera_session_state_path();
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode(file_get_contents($path) ?: '', true);
    return is_array($decoded) ? $decoded : [];
}

function write_camera_session_state(array $state): void
{
    @file_put_contents(camera_session_state_path(), json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function clear_camera_session_state(): void
{
    $path = camera_session_state_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

function camera_session_expires_at(): int
{
    return (int)(read_camera_session_state()['expires_at'] ?? 0);
}

function touch_camera_session_lease(?int $ttl = null): int
{
    $expiresAt = time() + ($ttl ?? camera_session_ttl());
    write_camera_session_state(['expires_at' => $expiresAt]);
    return $expiresAt;
}

function with_camera_lock(callable $action): mixed
{
    $handle = fopen(camera_session_lock_path(), 'c+');
    if ($handle === false) {
        throw new ApiError('internal_error', 'Failed to open camera session lock.', 500);
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new ApiError('internal_error', 'Failed to acquire camera session lock.', 500);
    }
    try {
        return $action();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function bridge_url(string $path): string
{
    $port = (int)(getenv('GARDEPRO_BRIDGE_PORT') ?: 18080);
    return 'http://' . bridge_host() . ':' . $port . $path;
}

function http_request(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 30): array
{
    $headerLines = [];
    foreach ($headers as $key => $value) {
        $headerLines[] = "{$key}: {$value}";
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    // The bridge board restarts its HTTP server every ~2 minutes
    // (idle_periodic housekeeping), which briefly drops the socket. A
    // request landing in that ~1-2s window fails to connect at all
    // (never reaches the server), so one short retry is safe to issue
    // for any method.
    $maxAttempts = 2;
    $response = false;
    $respHeaders = [];
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $response = @file_get_contents($url, false, $context);
        $respHeaders = $http_response_header ?? [];
        if ($response !== false) {
            break;
        }
        if ($attempt < $maxAttempts) {
            usleep(500000);
        }
    }
    $status = 599;
    if ($respHeaders && preg_match('/\s(\d{3})\s/', $respHeaders[0], $m)) {
        $status = (int)$m[1];
    }
    if ($response === false) {
        throw new ApiError('bridge_unreachable', 'Bridge request failed.', 503, $url);
    }
    return ['status' => $status, 'headers' => $respHeaders, 'body' => $response];
}

function bridge_json(string $method, string $path, ?array $query = null, mixed $payload = null): mixed
{
    $url = bridge_url($path);
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
    $headers = $body === null ? [] : ['Content-Type' => 'application/json'];
    $result = http_request($method, $url, $body, $headers, (int)(getenv('GARDEPRO_HTTP_TIMEOUT') ?: 30));
    $decoded = json_decode($result['body'], true);
    if ($result['status'] >= 400 || $decoded === null && trim($result['body']) !== '') {
        throw new ApiError('camera_action_failed', 'Bridge returned an unexpected response.', 502, [
            'status' => $result['status'],
            'body' => substr($result['body'], 0, 500),
        ]);
    }
    return $decoded ?? [];
}

function bridge_query(string $method, string $path, ?array $query = null, int $timeout = 30): array
{
    $url = bridge_url($path);
    if ($query) {
        $query = array_filter($query, static fn($value) => $value !== null);
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
    }
    return http_request($method, $url, null, [], $timeout);
}

function is_bridge_action_conflict(ApiError $error, string $action): bool
{
    if ($error->errorCode !== 'camera_action_failed' || !is_array($error->details)) {
        return false;
    }
    $status = (int)($error->details['status'] ?? 0);
    $body = (string)($error->details['body'] ?? '');
    return $status === 409 && str_contains($body, "\"action\":\"{$action}\"");
}

function bridge_error_code(ApiError $error): ?string
{
    if (!is_array($error->details)) {
        return null;
    }
    $body = (string)($error->details['body'] ?? '');
    if ($body === '') {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
}

function bridge_status(): array
{
    $status = bridge_json('GET', '/status');
    return is_array($status) ? $status : [];
}

function bridge_control_summary(): array
{
    $summary = bridge_json('GET', '/control/summary');
    $summary = is_array($summary) ? $summary : [];
    // live_view_active is authoritative on current firmware. Older
    // firmware doesn't report it here at all, so fall back to the
    // dedicated stream status route rather than inferring state from
    // control_last_action (which isn't a reliable "is it active" signal).
    if (!array_key_exists('live_view_active', $summary)) {
        try {
            $streamStatus = bridge_json('GET', '/stream/status');
        } catch (Throwable) {
            $streamStatus = [];
        }
        $streamStatus = is_array($streamStatus) ? $streamStatus : [];
        $summary['live_view_active'] = !empty($streamStatus['stream_active']);
        if (!array_key_exists('tunnel_connected', $summary)) {
            $summary['tunnel_connected'] = !empty($streamStatus['tunnel_connected']);
        }
    }
    return $summary;
}

function live_health_state_path(): string
{
    return '/tmp/trail_cam_live_health.json';
}

function live_recovery_grace_ms(): int
{
    return max(5000, (int)(getenv('GARDEPRO_LIVE_RECOVERY_GRACE_MS') ?: 20000));
}

function submit_live_view_job(string $action): array
{
    $response = bridge_raw_json('POST', '/jobs', null, ['action' => $action]);
    if ($response['status'] >= 200 && $response['status'] < 300) {
        return $response['body'];
    }
    // Firmware before 0.2.7 did not expose live view as a durable job.
    if (in_array($response['status'], [404, 405], true)) {
        $legacyAction = $action === 'live_view_start' ? 'stream_start' : 'stream_stop';
        return bridge_json('POST', '/control/' . $legacyAction);
    }
    throw new ApiError('camera_action_failed', 'Bridge rejected the live-view job.', 502, $response);
}

function live_job_poll_path(array $job): string
{
    $path = (string)($job['poll_path'] ?? '/control/summary');
    return str_starts_with($path, '/') ? $path : '/control/summary';
}

function live_stream_health(array $control): array
{
    try {
        $controlStatus = bridge_json('GET', '/control/status');
    } catch (Throwable) {
        $controlStatus = [];
    }
    try {
        $stream = bridge_json('GET', '/stream/status');
    } catch (Throwable $error) {
        $stream = [];
    }
    $controlStatus = is_array($controlStatus) ? $controlStatus : [];
    $stream = is_array($stream) ? $stream : [];
    $boardStateKnown = !empty($control) || !empty($controlStatus) || !empty($stream);
    $stats = is_array($stream['stream_status'] ?? null) ? $stream['stream_status'] : $stream;
    $streamActive = array_key_exists('stream_active', $stream)
        ? !empty($stream['stream_active'])
        : !empty($control['live_view_active']);
    $tunnelConnected = array_key_exists('tunnel_connected', $stream)
        ? !empty($stream['tunnel_connected'])
        : !empty($control['tunnel_connected']);
    $udpPrimary = is_array($stats['udp_primary'] ?? null) ? $stats['udp_primary'] : [];
    $packetAgeMs = isset($udpPrimary['last_packet_age_ms']) ? (int)$udpPrimary['last_packet_age_ms'] : null;
    $packetsSent = isset($stats['tunnel_packets_sent']) ? (int)$stats['tunnel_packets_sent'] : null;
    $hasRtpReceiving = array_key_exists('rtp_receiving', $stats);
    $rtpReceiving = $hasRtpReceiving ? !empty($stats['rtp_receiving']) : !empty($stats['rtp_flowing']);
    $hasRtpQuality = array_key_exists('rtp_quality_ok', $stats);
    $rtpQualityOk = $hasRtpQuality ? !empty($stats['rtp_quality_ok']) : null;
    $rtpFlowing = !empty($stats['rtp_flowing']);
    $rtpStalled = !empty($stats['rtp_stalled']);
    $streamSessionId = isset($stats['stream_session_id']) ? (string)$stats['stream_session_id'] : null;
    $lossPct = isset($stats['rtp_loss_pct']) ? (float)$stats['rtp_loss_pct'] : null;
    $packetsMissing = isset($stats['rtp_packets_missing']) ? (int)$stats['rtp_packets_missing'] : null;
    $sequenceGaps = isset($stats['rtp_sequence_gaps']) ? (int)$stats['rtp_sequence_gaps'] : null;
    $queue = is_array($stats['forward_queue'] ?? null) ? $stats['forward_queue'] : [];
    $queueDrops = isset($queue['dropped']) ? (int)$queue['dropped'] : null;
    $udpOverruns = isset($stats['udp_receive_overruns']) ? (int)$stats['udp_receive_overruns'] : null;
    $previous = json_decode((string)@file_get_contents(live_health_state_path()), true);
    $previous = is_array($previous) ? $previous : [];
    $observedAtMs = (int)floor(microtime(true) * 1000);
    $previewFrame = live_mjpeg_frame_count();
    $previousPreviewFrame = isset($previous['preview_frame']) ? (int)$previous['preview_frame'] : null;
    $previewFrameAdvanced = $previewFrame !== null && ($previousPreviewFrame === null || $previewFrame > $previousPreviewFrame);
    $lastPreviewFrameAtMs = $previewFrameAdvanced ? $observedAtMs : (int)($previous['last_preview_frame_at_ms'] ?? 0);
    $previewReady = !live_mjpeg_process_running() ? null : ($previewFrame !== null && $lastPreviewFrameAtMs > 0 && ($observedAtMs - $lastPreviewFrameAtMs) <= 5000);
    $packetsIncreasing = null;
    if ($packetsSent !== null && isset($previous['tunnel_packets_sent'])) {
        $packetsIncreasing = $packetsSent > (int)$previous['tunnel_packets_sent'];
    }
    $sessionChanged = $streamSessionId !== null && isset($previous['stream_session_id'])
        && (string)$previous['stream_session_id'] !== $streamSessionId;
    $qualityBadSamples = $hasRtpQuality && $rtpReceiving && !$rtpQualityOk
        ? ((int)($previous['quality_bad_samples'] ?? 0) + 1)
        : 0;
    @file_put_contents(live_health_state_path(), json_encode([
        'tunnel_packets_sent' => $packetsSent,
        'stream_active' => $streamActive,
        'stream_session_id' => $streamSessionId,
        'quality_bad_samples' => $qualityBadSamples,
        'preview_frame' => $previewFrame,
        'last_preview_frame_at_ms' => $lastPreviewFrameAtMs ?: null,
        'last_active_at_ms' => $streamActive ? $observedAtMs : ($previous['last_active_at_ms'] ?? null),
        'observed_at_ms' => $observedAtMs,
    ]), LOCK_EX);

    $state = 'inactive';
    $message = 'Live stream is inactive.';
    $restartRequired = false;
    $controlError = $controlStatus['control_error'] ?? $control['control_error'] ?? null;
    $lastActiveAtMs = (int)($previous['last_active_at_ms'] ?? 0);
    $withinRecoveryGrace = !$streamActive && $lastActiveAtMs > 0 && ($observedAtMs - $lastActiveAtMs) <= live_recovery_grace_ms();
    $recoveryBusy = !empty($controlStatus['control_busy']) && in_array((string)($controlStatus['control_action'] ?? ''), ['stream_start', 'stream_recover', 'live_view_start', 'live_view_recover'], true);
    $recentExplicitStop = !$streamActive && !$recoveryBusy
        && in_array((string)($controlStatus['control_last_action'] ?? ''), ['stream_stop', 'live_view_stop'], true)
        && !empty($controlStatus['control_last_ok']);
    if (!empty($controlError)) {
        $state = 'control_error';
        $message = 'Bridge/camera start failed: ' . $controlError;
        $restartRequired = true;
    } elseif (!$boardStateKnown) {
        $state = 'board_state_unknown';
        $message = 'Bridge status is temporarily unreachable; stream state is unknown.';
    } elseif (!$recentExplicitStop && ($withinRecoveryGrace || $recoveryBusy)) {
        $state = 'recovering';
        $message = 'Bridge is recovering the live stream; waiting for fresh RTP.';
    } elseif (!$streamActive) {
        $state = 'inactive';
    } elseif (!$tunnelConnected) {
        $state = 'camera_stream_local_only';
        $message = 'Camera stream is active on the bridge, but the server relay is not connected.';
    } elseif ($rtpStalled || ($packetAgeMs !== null && $packetAgeMs > 15000)) {
        $state = 'camera_rtp_stalled';
        $message = 'Camera RTP has stalled; waiting for bridge recovery.';
    } elseif ($hasRtpReceiving && !$rtpReceiving) {
        $state = 'waiting_for_camera_rtp';
        $message = 'Bridge tunnel is connected; waiting for camera RTP packets.';
    } elseif ($hasRtpQuality && $rtpReceiving && !$rtpQualityOk && $qualityBadSamples >= 3) {
        $state = 'receiving_degraded_media';
        $message = 'Live media is arriving, but RTP loss exceeds the quality limit.';
    } elseif ($hasRtpQuality && $rtpReceiving && $rtpQualityOk) {
        $state = 'receiving_live_media';
        $message = 'Server relay is receiving healthy live media.';
    } elseif ($hasRtpQuality && $rtpReceiving) {
        $state = 'receiving_live_media';
        $message = 'Server relay is receiving live media; validating RTP quality.';
    } elseif ($rtpFlowing && $packetAgeMs !== null && $packetAgeMs < 5000 && $packetsIncreasing === false) {
        $state = 'relay_not_moving_media';
        $message = 'Bridge tunnel is connected but is not forwarding new media packets.';
        $restartRequired = true;
    } elseif ($rtpFlowing && !$rtpStalled && $packetAgeMs !== null && $packetAgeMs < 5000 && $packetsIncreasing === true) {
        $state = 'receiving_live_media';
        $message = 'Server relay is receiving live media.';
    } else {
        $state = 'waiting_for_camera_rtp';
        $message = 'Bridge tunnel is connected; waiting for camera RTP packets.';
    }
    if ($previewReady === false && in_array($state, ['receiving_live_media', 'receiving_degraded_media'], true)) {
        $state = 'preview_unavailable';
        $message = 'RTP is arriving, but the server decoder has not produced a recent preview frame.';
    }
    return [
        'state' => $state,
        'message' => $message,
        'restart_required' => $restartRequired,
        'stream_active' => $streamActive,
        'tunnel_connected' => $tunnelConnected,
        'udp_primary_last_packet_age_ms' => $packetAgeMs,
        'tunnel_packets_sent' => $packetsSent,
        'tunnel_packets_increasing' => $packetsIncreasing,
        'rtp_flowing' => $rtpFlowing,
        'rtp_receiving' => $rtpReceiving,
        'rtp_quality_ok' => $rtpQualityOk,
        'rtp_quality_bad_samples' => $qualityBadSamples,
        'rtp_stalled' => $rtpStalled,
        'stream_session_id' => $streamSessionId,
        'stream_session_changed' => $sessionChanged,
        'rtp_loss_pct' => $lossPct,
        'rtp_packets_missing' => $packetsMissing,
        'rtp_sequence_gaps' => $sequenceGaps,
        'forward_queue_dropped' => $queueDrops,
        'udp_receive_overruns' => $udpOverruns,
        'media_receiving' => $rtpReceiving && !$rtpStalled,
        'preview_frame' => $previewFrame,
        'preview_frame_advanced' => $previewFrameAdvanced,
        'preview_ready' => $previewReady,
        'recovering' => $state === 'recovering',
        'board_state_known' => $boardStateKnown,
        'control_status' => $controlStatus,
        'stream_status' => $stream,
    ];
}

function control_error_abort(array $s): void
{
    if (!empty($s['control_error'])) {
        throw new ApiError('control_error', (string)$s['control_error'], 502, $s);
    }
}


function cached_bridge_status(): array
{
    $status = remember_api_cache('status', 'bridge.status', function () {
        $fresh = bridge_status();
        persist_board_status_snapshot($fresh);
        return $fresh;
    });
    if (is_array($status) && $status) {
        persist_board_status_snapshot($status);
        return $status;
    }
    return [];
}

function board_status_identity(array $raw): array
{
    return [
        'hostname' => $raw['hostname'] ?? null,
        'boot_count' => $raw['boot_count'] ?? null,
        'boot_session_id' => $raw['boot_session_id'] ?? null,
        'wifi_connected' => !empty($raw['wifi_connected']),
        'wifi_ip' => $raw['wifi_ip'] ?? null,
        'halow_connected' => !empty($raw['halow_connected']),
        'halow_ip' => $raw['halow_ip'] ?? null,
        'stream_active' => !empty($raw['stream_active']),
        'tunnel_connected' => !empty($raw['tunnel_connected']),
        'ble_stage' => $raw['ble_stage'] ?? null,
        'control_busy' => !empty($raw['control_busy']),
        'control_action' => $raw['control_action'] ?? null,
        'control_last_action' => $raw['control_last_action'] ?? null,
        'control_last_ok' => $raw['control_last_ok'] ?? null,
        'control_last_message' => $raw['control_last_message'] ?? null,
        'standby_requested' => $raw['standby_requested'] ?? null,
        'camera_session' => is_array($raw['camera_session'] ?? null) ? $raw['camera_session'] : null,
        'timing' => is_array($raw['timing'] ?? null) ? $raw['timing'] : null,
        'battery' => is_array($raw['battery'] ?? null) ? $raw['battery'] : null,
    ];
}

function board_status_hash(array $raw): string
{
    return hash('sha256', json_encode(board_status_identity($raw), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function persist_board_status_snapshot(array $raw): void
{
    if (!$raw) {
        return;
    }
    $pdo = db();
    $scannerHost = (string)($raw['hostname'] ?? bridge_host());
    $statusHash = board_status_hash($raw);

    $latestStmt = $pdo->prepare('SELECT status_hash, recorded_at FROM board_status_snapshots WHERE scanner_host = ? ORDER BY recorded_at DESC, id DESC LIMIT 1');
    $latestStmt->execute([$scannerHost]);
    $latest = $latestStmt->fetch();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    if ($latest) {
        $latestHash = (string)($latest['status_hash'] ?? '');
        $latestRecordedAt = isset($latest['recorded_at']) ? new DateTimeImmutable((string)$latest['recorded_at'], new DateTimeZone('UTC')) : null;
        $ageSeconds = $latestRecordedAt ? max(0, $now->getTimestamp() - $latestRecordedAt->getTimestamp()) : PHP_INT_MAX;
        if ($latestHash === $statusHash && $ageSeconds < board_status_store_interval()) {
            return;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO board_status_snapshots
        (scanner_host, recorded_at, status_hash, halow_connected, wifi_connected, stream_active, tunnel_connected, ble_stage, control_last_message, battery, camera_session, timing, payload)
        VALUES
        (:scanner_host, :recorded_at, :status_hash, :halow_connected, :wifi_connected, :stream_active, :tunnel_connected, :ble_stage, :control_last_message, :battery, :camera_session, :timing, :payload)
    ");
    $stmt->execute([
        ':scanner_host' => $scannerHost,
        ':recorded_at' => $now->format('Y-m-d H:i:s'),
        ':status_hash' => $statusHash,
        ':halow_connected' => isset($raw['halow_connected']) ? (int)(bool)$raw['halow_connected'] : null,
        ':wifi_connected' => isset($raw['wifi_connected']) ? (int)(bool)$raw['wifi_connected'] : null,
        ':stream_active' => isset($raw['stream_active']) ? (int)(bool)$raw['stream_active'] : null,
        ':tunnel_connected' => isset($raw['tunnel_connected']) ? (int)(bool)$raw['tunnel_connected'] : null,
        ':ble_stage' => $raw['ble_stage'] ?? null,
        ':control_last_message' => $raw['control_last_message'] ?? null,
        ':battery' => json_col($raw['battery'] ?? null),
        ':camera_session' => json_col($raw['camera_session'] ?? null),
        ':timing' => json_col($raw['timing'] ?? null),
        ':payload' => json_col($raw),
    ]);
}

function maybe_close_expired_camera_session(): void
{
    $expiresAt = camera_session_expires_at();
    if ($expiresAt <= 0 || $expiresAt > time()) {
        return;
    }
    with_camera_lock(function () use ($expiresAt): void {
        $currentExpiry = camera_session_expires_at();
        if ($currentExpiry !== $expiresAt || $currentExpiry > time()) {
            return;
        }
        try {
            $status = bridge_control_summary();
        } catch (Throwable) {
            clear_camera_session_state();
            return;
        }
        if (!empty($status['live_view_active'])) {
            touch_camera_session_lease();
            return;
        }
        if (!empty($status['wifi_connected'])) {
            try {
                close_session(['standby' => true]);
            } catch (Throwable) {
            }
        }
        invalidate_api_cache('bridge.status');
        clear_camera_session_state();
    });
}

function wait_for(callable $fetch, callable $predicate, int $timeout, float $interval, ?callable $abort = null): mixed
{
    $deadline = microtime(true) + $timeout;
    $last = null;
    while (microtime(true) < $deadline) {
        $last = $fetch();
        if ($abort !== null) {
            $abort($last);
        }
        if ($predicate($last)) {
            return $last;
        }
        usleep((int)($interval * 1000000));
    }
    throw new ApiError('camera_timeout', 'Timed out waiting for camera state.', 504, $last);
}

function wait_for_live_stream(string $pollPath, int $timeout, float $interval): array
{
    return wait_for(
        function () use ($pollPath): array {
            $summary = bridge_json('GET', $pollPath);
            $summary = is_array($summary) ? $summary : [];
            $controlStatus = bridge_json('GET', '/control/status');
            if (is_array($controlStatus) && !empty($controlStatus['control_error'])) {
                $summary['control_error'] = $controlStatus['control_error'];
            }
            return $summary;
        },
        fn($s) => is_array($s) && !empty($s['live_view_active']) && empty($s['control_busy']) && !empty($s['control_last_ok']),
        $timeout,
        $interval,
        'control_error_abort'
    );
}

function camera_json(string $method, string $cameraPath, mixed $payload = null, bool $autoBringup = true): mixed
{
    $normalizedMethod = strtoupper($method);
    $query = ['path' => $cameraPath];
    $bridgeMethod = 'GET';
    if ($payload !== null || $normalizedMethod !== 'GET') {
        $bridgeMethod = 'POST';
        $query['method'] = $normalizedMethod;
        if ($payload !== null) {
            $query['content_type'] = 'application/json';
        }
    }
    try {
        $result = bridge_json($bridgeMethod, '/camera/request', $query, $payload);
    } catch (ApiError $error) {
        $errCode = bridge_error_code($error);
        if (!$autoBringup || !in_array($errCode, ['camera_connect_failed', 'camera_wifi_down', 'camera_timeout', 'camera_busy'], true)) {
            throw $error;
        }
        if ($errCode === 'camera_busy') {
            usleep(2500000);
            $result = bridge_json($bridgeMethod, '/camera/request', $query, $payload);
        } else {
            open_session([]);
            $result = bridge_json($bridgeMethod, '/camera/request', $query, $payload);
        }
    }
    if ($autoBringup && is_array($result) && in_array(($result['error'] ?? ''), ['camera_connect_failed', 'camera_wifi_down', 'camera_timeout'], true)) {
        open_session([]);
        $result = bridge_json($bridgeMethod, '/camera/request', $query, $payload);
    }
    return $result;
}

function open_session(array $body): array
{
    // A cold Bluetooth wake can take well over one minute before the camera
    // exposes its Wi-Fi hotspot. Keep this aligned with nginx's 180s
    // FastCGI timeout so a valid desktop-bridge bring-up is not reported as
    // a web failure.
    $timeout = (int)($body['timeout_sec'] ?? getenv('GARDEPRO_CONTROL_TIMEOUT') ?: 180);
    $interval = (float)($body['poll_interval_sec'] ?? 1);
    $before = bridge_status();
    if (!empty($before['wifi_connected'])) {
        touch_camera_session_lease();
        return ['session_ready' => true, 'status' => $before, 'before' => $before];
    }
    try {
        $bringupResult = bridge_json('POST', '/control/bringup');
        // The bridge ETA is informative, not a client deadline. Retain the
        // 180-second web allowance for an unusually slow Bluetooth wake.
        $timeout = max($timeout, (int)($bringupResult['eta_sec'] ?? 0));
    } catch (ApiError $error) {
        if (!is_bridge_action_conflict($error, 'bringup')) {
            $summary = bridge_control_summary();
            if (empty($summary['control_busy']) && ($summary['control_state'] ?? '') !== 'bringing_up') {
                throw $error;
            }
        }
    }
    $after = wait_for(
        fn() => bridge_control_summary(),
        fn($s) => is_array($s) && !empty($s['wifi_connected']) && empty($s['control_busy']),
        $timeout,
        $interval,
        'control_error_abort'
    );
    touch_camera_session_lease();
    invalidate_api_cache('bridge.status');
    return ['session_ready' => !empty($after['wifi_connected']), 'status' => $after, 'before' => $before];
}

function close_session(array $body): array
{
    // The relay is local to this server and must not outlive any path that
    // closes the camera session (Stop Live, Disconnect, or lease expiry).
    stop_live_mjpeg_relay();
    $timeout = (int)($body['timeout_sec'] ?? getenv('GARDEPRO_CONTROL_TIMEOUT') ?: 45);
    $interval = (float)($body['poll_interval_sec'] ?? 2);
    $standby = (bool)($body['standby'] ?? true);
    $before = bridge_status();
    $beforeControl = bridge_control_summary();
    $streamStop = null;
    $standbyResult = null;
    if (!empty($beforeControl['live_view_active'])) {
        $streamStop = submit_live_view_job('live_view_stop');
        $stopTimeout = (int)($streamStop['eta_sec'] ?? $timeout);
        $stopPollPath = live_job_poll_path($streamStop);
        wait_for(fn() => bridge_json('GET', $stopPollPath), fn($s) => is_array($s) && empty($s['live_view_active']) && empty($s['tunnel_connected']) && empty($s['control_busy']) && !empty($s['control_last_ok']), $stopTimeout, $interval, 'control_error_abort');
    }
    if ($standby && !empty($before['wifi_connected'])) {
        try {
            $standbyResult = camera_json('GET', '/cmd/standby/now', null, false);
        } catch (ApiError $error) {
            // The desktop bridge already puts the camera in standby (and drops
            // its Wi-Fi) after stream_stop/photo, so a wifi-down reply here
            // means the goal is already met.
            if (bridge_error_code($error) !== 'camera_wifi_down') {
                throw $error;
            }
            $standbyResult = ['code' => 0, 'desc' => 'already_standby'];
        }
    }
    $after = wait_for(fn() => bridge_control_summary(), fn($s) => is_array($s) && empty($s['live_view_active']) && (!$standby || empty($s['wifi_connected'])), $timeout, $interval, 'control_error_abort');
    invalidate_api_cache('bridge.status');
    clear_camera_session_state();
    return [
        'session_closed' => empty($after['live_view_active']) && (!$standby || empty($after['wifi_connected'])),
        'standby_requested' => $standby,
        'status' => $after,
        'before' => $before,
        'stream_stop' => $streamStop,
        'standby' => $standbyResult,
    ];
}

// Takes a photo through the bridge's trigger_photo job, which waits for the
// camera's result, then reports the finished photo from /control/summary
// (last_photo.media_id / file_path) instead of the camera's first
// "taking is on-going" reply. Falls back to the raw camera call on bridges
// without the job (e.g. the ESP32).
function take_picture_via_bridge(): array
{
    $beforePhoto = bridge_control_summary()['last_photo'] ?? null;
    $beforeId = is_array($beforePhoto) ? ($beforePhoto['media_id'] ?? null) : null;
    $response = bridge_raw_json('POST', '/jobs', ['action' => 'trigger_photo'], null, 120);
    if (in_array($response['status'], [404, 405], true)) {
        $legacy = camera_json('GET', '/media/pic/take');
        return is_array($legacy) ? $legacy : ['result' => $legacy];
    }
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new ApiError('camera_action_failed', 'Bridge rejected the photo job.', 502, $response);
    }
    $summary = [];
    try {
        $summary = wait_for(
            fn() => bridge_control_summary(),
            fn($s) => is_array($s) && is_array($s['last_photo'] ?? null) && ($s['last_photo']['media_id'] ?? null) !== $beforeId,
            (int)(getenv('GARDEPRO_PHOTO_TIMEOUT') ?: 60),
            1.0,
            'control_error_abort'
        );
    } catch (ApiError $error) {
        // Fall through: report the job reply without a photo rather than
        // failing a photo that may still be saving.
    }
    $photo = is_array($summary) && is_array($summary['last_photo'] ?? null) ? $summary['last_photo'] : null;
    $newPhoto = $photo !== null && ($photo['media_id'] ?? null) !== $beforeId;
    return [
        'photo_taken' => $newPhoto,
        'last_photo' => $newPhoto ? $photo : null,
        'job' => $response['body'],
    ];
}

function with_camera_session(callable $action): mixed
{
    return with_camera_lock(function () use ($action): mixed {
        open_session([]);
        $result = $action();
        touch_camera_session_lease();
        return $result;
    });
}

function normalize_status(array $raw): array
{
    return [
        'camera_reachable' => !empty($raw['wifi_connected']),
        'session_open' => !empty($raw['wifi_connected']),
        'live_view_active' => array_key_exists('live_view_active', $raw) ? !empty($raw['live_view_active']) : !empty($raw['stream_active']),
        'halow_connected' => !empty($raw['halow_connected']),
        'wifi_connected' => !empty($raw['wifi_connected']),
        'tunnel_connected' => !empty($raw['tunnel_connected']),
        'camera_ip' => $raw['camera_ip'] ?? '192.168.8.1',
        'camera_target_ble_mac' => $raw['camera_target_ble_mac'] ?? null,
        'camera_target_ble_name' => $raw['camera_target_ble_name'] ?? null,
        'camera_target_wifi_ssid' => $raw['camera_target_wifi_ssid'] ?? null,
        'bridge_ip' => bridge_host(),
        'ble_stage' => $raw['ble_stage'] ?? null,
        'standby_requested' => !empty($raw['standby_requested']),
        'control_last_message' => $raw['control_last_message'] ?? null,
        'control_message' => $raw['control_message'] ?? null,
        'control_state' => $raw['control_state'] ?? null,
        'control_error' => $raw['control_error'] ?? null,
        'control_progress_text' => $raw['control_progress_text'] ?? null,
        'raw' => $raw,
    ];
}

function normalize_board_status_snapshot(array $row): array
{
    $payload = json_decode_array($row['payload'] ?? null);
    $battery = json_decode_array($row['battery'] ?? null);
    $cameraSession = json_decode_array($row['camera_session'] ?? null);
    $timing = json_decode_array($row['timing'] ?? null);

    return [
        'status_id' => (int)($row['id'] ?? 0),
        'scanner_host' => $row['scanner_host'] ?? ($payload['hostname'] ?? null),
        'recorded_at' => $row['recorded_at'] ?? null,
        'status_hash' => $row['status_hash'] ?? null,
        'halow_connected' => isset($row['halow_connected']) ? (bool)$row['halow_connected'] : !empty($payload['halow_connected']),
        'wifi_connected' => isset($row['wifi_connected']) ? (bool)$row['wifi_connected'] : !empty($payload['wifi_connected']),
        'stream_active' => isset($row['stream_active']) ? (bool)$row['stream_active'] : !empty($payload['stream_active']),
        'tunnel_connected' => isset($row['tunnel_connected']) ? (bool)$row['tunnel_connected'] : !empty($payload['tunnel_connected']),
        'ble_stage' => $row['ble_stage'] ?? ($payload['ble_stage'] ?? null),
        'control_last_message' => $row['control_last_message'] ?? ($payload['control_last_message'] ?? null),
        'battery_est_v' => first_numeric($battery, ['battery_est_v']),
        'battery_adc_mv' => first_numeric($battery, ['adc_mv']),
        'lease_active' => !empty($cameraSession['lease_active']),
        'last_bringup_elapsed_ms' => isset($timing['last_bringup_elapsed_ms']) && is_numeric($timing['last_bringup_elapsed_ms']) ? (int)$timing['last_bringup_elapsed_ms'] : null,
        'camera_session' => $cameraSession,
        'timing' => $timing,
        'battery' => $battery,
        'payload' => $payload,
    ];
}

function latest_board_status_snapshot(?string $scannerHost = null): ?array
{
    $pdo = db();
    if ($scannerHost !== null && $scannerHost !== '') {
        $stmt = $pdo->prepare('SELECT * FROM board_status_snapshots WHERE scanner_host = ? ORDER BY recorded_at DESC, id DESC LIMIT 1');
        $stmt->execute([$scannerHost]);
    } else {
        $stmt = $pdo->query('SELECT * FROM board_status_snapshots ORDER BY recorded_at DESC, id DESC LIMIT 1');
    }
    $row = $stmt->fetch();
    return $row ? normalize_board_status_snapshot($row) : null;
}

function onboard_status(): array
{
    $status = bridge_json('GET', '/onboard/status');
    return is_array($status) ? $status : [];
}

function onboard_query_from_payload(array $body): array
{
    $query = [];
    foreach ($body as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        if (is_bool($value)) {
            $query[$key] = $value ? 'true' : 'false';
            continue;
        }
        if (is_scalar($value)) {
            $query[$key] = (string)$value;
        }
    }
    return $query;
}

function onboard_response_error(array $result, string $fallbackMessage): never
{
    $decoded = json_decode($result['body'], true);
    $message = $fallbackMessage;
    $details = [
        'status' => $result['status'],
        'body' => substr($result['body'], 0, 500),
    ];

    if (is_array($decoded)) {
        if (!empty($decoded['error']) && is_string($decoded['error'])) {
            $message = $decoded['error'];
        } elseif (array_key_exists('ok', $decoded) && $decoded['ok'] === false) {
            $message = $fallbackMessage;
        }
        $details['bridge'] = $decoded;
    }

    throw new ApiError('camera_action_failed', $message, 502, $details);
}

function onboard_capture(array $body): array
{
    $result = bridge_query('POST', '/onboard/capture', onboard_query_from_payload($body), (int)(getenv('GARDEPRO_HTTP_TIMEOUT') ?: 60));
    $decoded = json_decode($result['body'], true);
    if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($decoded)) {
        onboard_response_error($result, 'Onboard capture failed.');
    }
    return $decoded;
}

function onboard_config(array $body): array
{
    $result = bridge_query('POST', '/onboard/config', onboard_query_from_payload($body));
    $decoded = json_decode($result['body'], true);
    if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($decoded)) {
        onboard_response_error($result, 'Onboard config update failed.');
    }
    return $decoded;
}

function onboard_timelapse_start(array $body): array
{
    $result = bridge_query('POST', '/onboard/timelapse/start', onboard_query_from_payload($body));
    $decoded = json_decode($result['body'], true);
    if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($decoded)) {
        onboard_response_error($result, 'Onboard timelapse start failed.');
    }
    return $decoded;
}

function onboard_timelapse_stop(): array
{
    $result = bridge_query('POST', '/onboard/timelapse/stop');
    $decoded = json_decode($result['body'], true);
    if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($decoded)) {
        onboard_response_error($result, 'Onboard timelapse stop failed.');
    }
    return $decoded;
}

function proxy_onboard_latest_jpg(): never
{
    $result = bridge_query('GET', '/onboard/latest.jpg', ['_ts' => time()], (int)(getenv('GARDEPRO_HTTP_TIMEOUT') ?: 30));
    if ($result['status'] < 200 || $result['status'] >= 300) {
        $decoded = json_decode($result['body'], true);
        if (is_array($decoded) && !empty($decoded['error'])) {
            throw new ApiError('not_found', (string)$decoded['error'], 404, $decoded);
        }
        throw new ApiError('camera_action_failed', 'Onboard latest image request failed.', 502, $result['status']);
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Content-Length: ' . strlen($result['body']));
    echo $result['body'];
    exit;
}

function onboard_media_list(array $query): array
{
    $forward = [];
    foreach (['offset', 'limit', 'sort', 'from', 'to'] as $key) {
        if (isset($query[$key]) && $query[$key] !== '') {
            $forward[$key] = (string)$query[$key];
        }
    }
    $result = bridge_query('GET', '/onboard/media', $forward, (int)(getenv('GARDEPRO_HTTP_TIMEOUT') ?: 30));
    $decoded = json_decode($result['body'], true);
    if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($decoded)) {
        onboard_response_error($result, 'Onboard media list failed.');
    }
    return $decoded;
}

function proxy_onboard_media_file(string $id, bool $thumb = false): never
{
    $path = $thumb ? "/onboard/media/{$id}/thumb" : "/onboard/media/{$id}";
    $result = bridge_query('GET', $path, ['_ts' => time()], (int)(getenv('GARDEPRO_HTTP_TIMEOUT') ?: 30));
    if ($result['status'] < 200 || $result['status'] >= 300) {
        $decoded = json_decode($result['body'], true);
        if (is_array($decoded) && !empty($decoded['error'])) {
            throw new ApiError('not_found', (string)$decoded['error'], $result['status'] === 404 ? 404 : 502, $decoded);
        }
        throw new ApiError('camera_action_failed', 'Onboard media request failed.', 502, $result['status']);
    }
    $contentType = 'image/jpeg';
    foreach ($result['headers'] as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') === 0) {
            $contentType = trim(substr($headerLine, strlen('Content-Type:')));
            break;
        }
    }
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Content-Length: ' . strlen($result['body']));
    echo $result['body'];
    exit;
}

function onboard_media_delete(string $id): array
{
    $result = bridge_query('DELETE', "/onboard/media/{$id}", null, (int)(getenv('GARDEPRO_HTTP_TIMEOUT') ?: 30));
    $decoded = json_decode($result['body'], true);
    if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($decoded)) {
        onboard_response_error($result, 'Onboard media delete failed.');
    }
    return $decoded;
}

function onboard_media_delete_all(): array
{
    $result = bridge_query('POST', '/onboard/media/delete_all', null, (int)(getenv('GARDEPRO_HTTP_TIMEOUT') ?: 60));
    $decoded = json_decode($result['body'], true);
    if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($decoded)) {
        onboard_response_error($result, 'Onboard media delete all failed.');
    }
    return $decoded;
}

function onboard_media_storage_dir(): string
{
    $dir = ensure_pictures_dir() . '/onboard';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new ApiError('internal_error', 'Failed to create onboard media storage directory.', 500);
    }
    return $dir;
}

function onboard_media_server_filename(array $item): string
{
    $id = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($item['id'] ?? 'unknown'));
    $recordedAt = preg_replace('/[^0-9]/', '', (string)($item['recorded_at'] ?? ''));
    return 'onboard_' . ($recordedAt ?: gmdate('YmdHis')) . '_' . $id . '.jpg';
}

function download_and_delete_onboard_media(): array
{
    $listing = onboard_media_list([]);
    $items = $listing['media'] ?? [];
    if (!is_array($items)) {
        throw new ApiError('camera_action_failed', 'Onboard media list had an unexpected format.', 502);
    }

    $dir = onboard_media_storage_dir();
    $downloaded = [];
    $skipped = [];
    $deleted = [];
    $failed = [];
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['id'])) {
            continue;
        }
        $id = (string)$item['id'];
        $path = $dir . '/' . onboard_media_server_filename($item);
        try {
            if (is_file($path) && filesize($path) > 0) {
                $skipped[] = ['id' => $id, 'path' => $path, 'bytes' => filesize($path)];
            } else {
                $result = bridge_query('GET', "/onboard/media/{$id}", ['_ts' => time()], (int)(getenv('GARDEPRO_HTTP_TIMEOUT') ?: 30));
                if ($result['status'] < 200 || $result['status'] >= 300 || $result['body'] === '') {
                    onboard_response_error($result, "Onboard media download failed for {$id}.");
                }
                $temporaryPath = $path . '.part';
                $written = @file_put_contents($temporaryPath, $result['body'], LOCK_EX);
                if ($written === false || $written !== strlen($result['body']) || !@rename($temporaryPath, $path)) {
                    @unlink($temporaryPath);
                    throw new ApiError('internal_error', "Failed to save onboard media {$id} on the server.", 500);
                }
                $downloaded[] = ['id' => $id, 'path' => $path, 'bytes' => $written];
            }
            onboard_media_delete($id);
            $deleted[] = $id;
        } catch (Throwable $error) {
            $failed[] = ['id' => $id, 'error' => $error->getMessage()];
        }
    }
    return [
        'directory' => $dir,
        'downloaded' => count($downloaded),
        'skipped' => count($skipped),
        'deleted' => count($deleted),
        'failed' => count($failed),
        'files' => $downloaded,
        'errors' => $failed,
    ];
}

function onboard_server_media_items(): array
{
    $dir = onboard_media_storage_dir();
    $items = [];
    foreach (glob($dir . '/onboard_*.jpg') ?: [] as $path) {
        $filename = basename($path);
        if (!preg_match('/^onboard_(\d+)_([A-Za-z0-9_-]+)\.jpg$/', $filename, $matches)) {
            continue;
        }
        $stamp = $matches[1];
        $recordedAt = strlen($stamp) === 14
            ? substr($stamp, 0, 4) . '-' . substr($stamp, 4, 2) . '-' . substr($stamp, 6, 2)
              . ' ' . substr($stamp, 8, 2) . ':' . substr($stamp, 10, 2) . ':' . substr($stamp, 12, 2)
            : gmdate('Y-m-d H:i:s', filemtime($path) ?: time());
        $items[] = [
            'id' => $matches[2],
            'filename' => $filename,
            'recorded_at' => $recordedAt,
            'bytes' => filesize($path),
            'capture_kind' => 'server copy',
            'download_path' => public_api_base() . '/onboard/server-media/' . rawurlencode($filename),
        ];
    }
    usort($items, fn($a, $b) => strcmp((string)$b['recorded_at'], (string)$a['recorded_at']));
    return $items;
}

function onboard_server_media_path(string $filename): string
{
    if ($filename !== basename($filename) || !preg_match('/^onboard_[A-Za-z0-9_-]+\.jpg$/', $filename)) {
        throw new ApiError('not_found', 'Server media item was not found.', 404);
    }
    $path = onboard_media_storage_dir() . '/' . $filename;
    if (!is_file($path) || !is_readable($path)) {
        throw new ApiError('not_found', 'Server media item was not found.', 404);
    }
    return $path;
}

function proxy_onboard_server_media_file(string $filename): never
{
    $path = onboard_server_media_path($filename);
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function delete_onboard_server_media_file(string $filename): array
{
    $path = onboard_server_media_path($filename);
    if (!@unlink($path)) {
        throw new ApiError('internal_error', 'Failed to delete server media item.', 500);
    }
    return ['deleted' => true, 'filename' => $filename];
}

function delete_selected_onboard_server_media(array $filenames): array
{
    $deleted = [];
    $errors = [];
    foreach ($filenames as $filename) {
        if (!is_string($filename)) {
            continue;
        }
        try {
            delete_onboard_server_media_file($filename);
            $deleted[] = $filename;
        } catch (Throwable $error) {
            $errors[] = ['filename' => $filename, 'error' => $error->getMessage()];
        }
    }
    return ['deleted' => $deleted, 'errors' => $errors];
}

function settings_schema(): array
{
    return ['fields' => [
        ['key' => 'standby_timeout', 'label' => 'Standby Timeout', 'type' => 'integer', 'editable' => true],
        ['key' => 'wifi', 'label' => 'WiFi', 'type' => 'integer', 'editable' => true],
        ['key' => 'date_format', 'label' => 'Date Format', 'type' => 'integer', 'editable' => true],
        ['key' => 'time_format', 'label' => 'Time Format', 'type' => 'integer', 'editable' => true],
        ['key' => 'temperature_format', 'label' => 'Temperature Format', 'type' => 'integer', 'editable' => true],
        ['key' => 'photo_or_video', 'label' => 'Photo or Video', 'type' => 'integer', 'editable' => true],
        ['key' => 'photo_quality', 'label' => 'Photo Quality', 'type' => 'integer', 'editable' => true],
        ['key' => 'video_quality', 'label' => 'Video Quality', 'type' => 'integer', 'editable' => true],
        ['key' => 'video_length', 'label' => 'Video Length', 'type' => 'integer', 'editable' => true],
    ]];
}

function safe_setting_keys(): array
{
    return array_column(settings_schema()['fields'], 'key');
}

function media_type_name(int $type): string
{
    return $type === 2 ? 'video' : 'photo';
}

function raw_gallery_items(): array
{
    $lastError = null;
    for ($attempt = 1; $attempt <= 3; $attempt += 1) {
        try {
            $payload = bridge_json('GET', '/camera/gallery');
            return is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : [];
        } catch (Throwable $error) {
            $lastError = $error;
            if ($attempt < 3) {
                try {
                    open_session([]);
                } catch (Throwable) {
                }
                usleep(250000);
                continue;
            }
        }
    }
    throw $lastError instanceof Throwable ? $lastError : new ApiError('camera_action_failed', 'Failed to load media gallery.');
}

function local_cached_media_ids(): array
{
    $dir = ensure_pictures_dir();
    $ids = [];
    foreach (glob($dir . '/trailcam_*.*') ?: [] as $file) {
        if (preg_match('/_(\d+)\.\w+$/', basename($file), $m)) {
            $ids[(int)$m[1]] = true;
        }
    }
    // Files pulled from /trail-media/files (the ESP32's durable-job
    // staging area) are named "<id>.<ext>" rather than "trailcam_*_<id>.*".
    foreach (glob($dir . '/*.*') ?: [] as $file) {
        if (preg_match('/^(\d+)\.(jpg|jpeg|mp4)$/i', basename($file), $m)) {
            $ids[(int)$m[1]] = true;
        }
    }
    return $ids;
}

function normalize_media_item(array $item, array $cachedIds = []): array
{
    $id = (int)($item['id'] ?? 0);
    $type = (int)($item['type'] ?? 1);
    return [
        'id' => $id,
        'type' => media_type_name($type),
        'timestamp' => $item['date'] ?? null,
        'size_bytes' => (int)($item['size'] ?? 0),
        'status' => $type === 2 && ($item['uid'] ?? '') === '00000000' ? 'pending' : 'ready',
        'cached' => isset($cachedIds[$id]),
        'download_path' => public_api_base() . "/media/{$id}/download",
        'thumbnail_path' => public_api_base() . "/media/{$id}/thumb",
        'raw' => $item,
    ];
}

function server_only_media_entry(int $id, string $ext, string $timestamp, int $bytes, ?string $sourcePath = null): array
{
    $thumbVersion = $sourcePath && is_file($sourcePath) ? (string)(filemtime($sourcePath) ?: 0) : '0';
    return [
        'id' => $id,
        'type' => $ext === 'mp4' ? 'video' : 'photo',
        'timestamp' => $timestamp,
        'size_bytes' => $bytes,
        'status' => 'server-only',
        'cached' => true,
        'server_only' => true,
        'download_path' => public_api_base() . "/media/{$id}/download",
        'thumbnail_path' => public_api_base() . "/media/{$id}/thumb?v={$thumbVersion}",
        'raw' => [],
    ];
}

function camera_media_timezone(): DateTimeZone
{
    try {
        return new DateTimeZone(getenv('TRAILCAM_CAMERA_TIMEZONE') ?: 'America/Detroit');
    } catch (Throwable) {
        return new DateTimeZone('America/Detroit');
    }
}

// Durable ESP32 downloads are named "<id>.<ext>", which has no capture
// time. GardePro puts it in JPEG EXIF and MP4 creation_time metadata. Read
// that value instead of presenting the time the server happened to copy it.
function camera_media_capture_timestamp(string $path, string $ext): ?string
{
    $ext = strtolower($ext);
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path, null, true, false);
        $value = is_array($exif) ? ($exif['EXIF']['DateTimeOriginal'] ?? $exif['IFD0']['DateTime'] ?? null) : null;
        if (is_string($value) && preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $value, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }
    }
    if ($ext === 'mp4' && is_executable('/usr/bin/ffprobe')) {
        $command = '/usr/bin/ffprobe -v error -show_entries format_tags=creation_time -of default=nw=1:nk=1 ' . escapeshellarg($path);
        $value = trim((string)@shell_exec($command));
        if ($value !== '') {
            try {
                return (new DateTimeImmutable($value))->setTimezone(camera_media_timezone())->format('Y-m-d H:i:s');
            } catch (Throwable) {
            }
        }
    }
    return null;
}

function server_only_media_items(array $cameraIds, array $cachedIds): array
{
    $dir = ensure_pictures_dir();
    $items = [];
    $seenIds = [];
    foreach (glob($dir . '/trailcam_*.*') ?: [] as $file) {
        if (!preg_match('/trailcam_(\d+)_(\d+)\.(jpg|mp4)$/i', basename($file), $m)) {
            continue;
        }
        $id = (int)$m[2];
        if (isset($cameraIds[$id]) || isset($seenIds[$id])) {
            continue;
        }
        $timestamp = $m[1];
        $formattedTs = strlen($timestamp) === 14
            ? substr($timestamp, 0, 4) . '-' . substr($timestamp, 4, 2) . '-' . substr($timestamp, 6, 2)
              . ' ' . substr($timestamp, 8, 2) . ':' . substr($timestamp, 10, 2) . ':' . substr($timestamp, 12, 2)
            : $timestamp;
        $items[] = server_only_media_entry($id, strtolower($m[3]), $formattedTs, filesize($file), $file);
        $seenIds[$id] = true;
    }
    // Files pulled from /trail-media/files (the ESP32's durable-job
    // staging area) are named "<id>.<ext>" rather than "trailcam_*_<id>.*".
    foreach (glob($dir . '/*.*') ?: [] as $file) {
        if (!preg_match('/^(\d+)\.(jpg|jpeg|mp4)$/i', basename($file), $m)) {
            continue;
        }
        $id = (int)$m[1];
        if (isset($cameraIds[$id]) || isset($seenIds[$id])) {
            continue;
        }
        $ext = strtolower($m[2]) === 'jpeg' ? 'jpg' : strtolower($m[2]);
        $formattedTs = camera_media_capture_timestamp($file, $ext)
            ?? (new DateTimeImmutable('@' . (filemtime($file) ?: time())))
                ->setTimezone(camera_media_timezone())
                ->format('Y-m-d H:i:s');
        $items[] = server_only_media_entry($id, $ext, $formattedTs, filesize($file), $file);
        $seenIds[$id] = true;
    }
    usort($items, fn($a, $b) => strcmp((string)$b['timestamp'], (string)$a['timestamp']));
    return $items;
}

function pushed_media_items(): array
{
    $stmt = db()->query("SELECT id, media_type, recorded_at, blob_path, blob_bytes FROM board_media WHERE blob_path IS NOT NULL AND blob_path <> '' ORDER BY recorded_at DESC, id DESC");
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['id'];
        $path = pushed_media_file_path($id);
        if ($id <= 0 || !$path) {
            continue;
        }
        $isVideo = strtolower((string)$row['media_type']) === 'video/mp4';
        $items[] = [
            // Board-media ids have a separate namespace from trail-camera ids.
            'id' => 'push-' . $id,
            'type' => $isVideo ? 'video' : 'photo',
            // Desktop uploads store the camera capture time in recorded_at.
            // Avoid ffprobe for every stored video during page load: a large
            // gallery otherwise blocks unrelated controls for seconds.
            'timestamp' => $row['recorded_at'],
            'size_bytes' => (int)($row['blob_bytes'] ?? filesize($path)),
            'status' => 'ESP32 upload',
            'cached' => true,
            'server_only' => true,
            'download_path' => public_api_base() . "/media/push/{$id}/download",
            'thumbnail_path' => public_api_base() . "/media/push/{$id}/thumb?v=" . (string)(filemtime($path) ?: 0),
            'raw' => [],
        ];
    }
    return $items;
}

function list_media_items(): array
{
    // The gallery is intentionally server-only.  Listing it must not wake,
    // connect to, or query the trail camera; camera media is fetched only by
    // an explicit download action.
    $cameraItems = [];
    $cachedIds = local_cached_media_ids();
    $cameraIds = [];
    foreach ($cameraItems as $item) {
        if (is_array($item) && !empty($item['id'])) {
            $cameraIds[(int)$item['id']] = true;
        }
    }
    $normalized = array_map(fn($item) => normalize_media_item(is_array($item) ? $item : [], $cachedIds), $cameraItems);
    $serverOnly = server_only_media_items($cameraIds, $cachedIds);
    $items = array_merge($normalized, $serverOnly, pushed_media_items());
    usort($items, fn($a, $b) => strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? '')));
    return $items;
}

function clear_server_media(): array
{
    $dir = ensure_pictures_dir();
    $files = array_values(array_unique(array_merge(
        glob($dir . '/trailcam_*.*') ?: [],
        glob($dir . '/*.jpg') ?: [],
        glob($dir . '/*.jpeg') ?: [],
        glob($dir . '/*.mp4') ?: []
    )));
    $thumbs = glob($dir . '/thumbs/*.jpg') ?: [];
    $deleted = [];
    $failed = [];
    foreach (array_merge($files, $thumbs) as $file) {
        if (@unlink($file)) {
            $deleted[] = basename($file);
        } else {
            $failed[] = basename($file);
        }
    }
    $pushed = db()->query("SELECT id, blob_path FROM board_media WHERE blob_path IS NOT NULL AND blob_path <> ''")->fetchAll();
    foreach ($pushed as $item) {
        $id = (int)$item['id'];
        $path = pushed_media_file_path($id);
        if (!$path || !@unlink($path)) {
            $failed[] = 'push-' . $id;
            continue;
        }
        db()->prepare('UPDATE board_media SET blob_path=NULL, blob_bytes=NULL, blob_sha256=NULL WHERE id=?')->execute([$id]);
        $deleted[] = 'push-' . $id;
    }
    invalidate_api_cache('media.gallery');
    return ['deleted' => count($deleted), 'failed' => count($failed), 'files' => $deleted];
}

function ensure_pictures_dir(): string
{
    $candidates = [];
    $configured = getenv('TRAILCAM_PICTURES_DIR') ?: '';
    if ($configured !== '') {
        $candidates[] = $configured;
    }
    $candidates[] = DEFAULT_PICTURES_DIR;
    $candidates[] = rtrim(sys_get_temp_dir(), '/') . '/trail_cam_pictures';

    foreach ($candidates as $candidate) {
        $dir = rtrim($candidate, '/');
        if ($dir === '') {
            continue;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            continue;
        }
        if (is_writable($dir)) {
            return $dir;
        }
    }
    throw new ApiError('internal_error', 'Failed to create pictures directory.', 500, $candidates);
}

function media_file_extension(array $item): string
{
    return ((int)($item['type'] ?? 1) === 2) ? 'mp4' : 'jpg';
}

function media_download_camera_path(array $item): string
{
    $id = (int)($item['id'] ?? 0);
    if ($id <= 0) {
        throw new ApiError('invalid_request', 'Media item is missing a valid id.', 400);
    }
    return media_file_extension($item) === 'mp4' ? "/file/{$id}/mp4" : "/file/{$id}/JPG";
}

function media_server_filename(array $item): string
{
    $id = (int)($item['id'] ?? 0);
    $timestamp = preg_replace('/[^0-9]/', '', (string)($item['date'] ?? ''));
    if ($timestamp === '') {
        $timestamp = gmdate('YmdHis');
    }
    return sprintf('trailcam_%s_%d.%s', $timestamp, $id, media_file_extension($item));
}

function download_single_media_to_server(array $item, string $dir): array
{
    $id = (int)($item['id'] ?? 0);
    $filename = media_server_filename($item);
    $path = $dir . '/' . $filename;
    if (is_file($path) && filesize($path) > 0) {
        return [
            'id' => $id,
            'path' => $path,
            'bytes' => filesize($path),
            'type' => media_type_name((int)($item['type'] ?? 1)),
            'timestamp' => $item['date'] ?? null,
            'attempts' => 0,
            'skipped' => true,
        ];
    }
    $lastError = null;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            $cameraPath = media_download_camera_path($item);
            $result = http_request('GET', bridge_url('/camera/raw?' . http_build_query(['path' => $cameraPath])), null, [], 60);
            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new ApiError('camera_action_failed', 'Bulk media download failed.', 502, [
                    'media_id' => (int)$item['id'],
                    'status' => $result['status'],
                ]);
            }
            $filename = media_server_filename($item);
            $path = $dir . '/' . $filename;
            $written = @file_put_contents($path, $result['body'], LOCK_EX);
            if ($written === false || $written !== strlen($result['body'])) {
                throw new ApiError('internal_error', 'Failed to persist downloaded media file.', 500, [
                    'media_id' => (int)$item['id'],
                    'path' => $path,
                ]);
            }
            return [
                'id' => (int)$item['id'],
                'path' => $path,
                'bytes' => $written,
                'type' => media_type_name((int)($item['type'] ?? 1)),
                'timestamp' => $item['date'] ?? null,
                'attempts' => $attempt,
            ];
        } catch (Throwable $error) {
            $lastError = $error;
            if ($attempt < 2) {
                open_session([]);
                continue;
            }
        }
    }

    $details = $lastError instanceof ApiError ? $lastError->details : $lastError?->getMessage();
    return [
        'id' => (int)($item['id'] ?? 0),
        'type' => media_type_name((int)($item['type'] ?? 1)),
        'timestamp' => $item['date'] ?? null,
        'error' => $lastError instanceof ApiError ? $lastError->getMessage() : 'Download failed.',
        'details' => $details,
    ];
}

function download_all_media_to_server(?callable $progressCallback = null): array
{
    return with_camera_lock(function () use ($progressCallback): array {
        open_session([]);
        $items = raw_gallery_items();
        $dir = ensure_pictures_dir();
        $downloaded = [];
        $skipped = [];
        $failed = [];
        $processed = 0;
        $total = 0;
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['id'])) {
                $total += 1;
            }
        }
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $result = download_single_media_to_server($item, $dir);
            if (isset($result['error'])) {
                $failed[] = $result;
            } elseif (!empty($result['skipped'])) {
                $skipped[] = $result;
            } else {
                $downloaded[] = $result;
            }
            $processed += 1;
            if ($progressCallback) {
                $progressCallback([
                    'stage' => 'downloading',
                    'processed' => $processed,
                    'total' => $total,
                    'current_id' => (int)$item['id'],
                    'downloaded' => count($downloaded),
                    'skipped' => count($skipped),
                    'failed' => count($failed),
                ]);
            }
            touch_camera_session_lease();
        }
        touch_camera_session_lease();
        invalidate_api_cache('bridge.status');
        return [
            'directory' => $dir,
            'downloaded' => count($downloaded),
            'skipped' => count($skipped),
            'failed' => count($failed),
            'files' => $downloaded,
            'errors' => $failed,
        ];
    });
}

function download_and_delete_media(?callable $progressCallback = null): array
{
    return with_camera_lock(function () use ($progressCallback): array {
        open_session([]);
        $items = raw_gallery_items();
        $dir = ensure_pictures_dir();
        $total = count(array_filter($items, fn($i) => is_array($i) && !empty($i['id'])));
        $downloaded = [];
        $skipped = [];
        $deleted = [];
        $failed = [];
        $processed = 0;
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $id = (int)$item['id'];
            $downloadResult = download_single_media_to_server($item, $dir);
            $downloadOk = !isset($downloadResult['error']);
            if ($downloadOk) {
                if (!empty($downloadResult['skipped'])) {
                    $skipped[] = $downloadResult;
                } else {
                    $downloaded[] = $downloadResult;
                }
            } else {
                $failed[] = $downloadResult;
            }
            if ($downloadOk) {
                $ext = strtoupper(media_file_extension($item)) === 'MP4' ? 'mp4' : 'JPG';
                $deleteError = null;
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    try {
                        $response = camera_json('GET', "/cmd/delete/{$id}/{$ext}", null, false);
                        if (is_array($response) && (($response['code'] ?? 0) === -2 || str_contains(strtolower((string)($response['desc'] ?? '')), 'file does not exist'))) {
                            break;
                        }
                        $deleted[] = $id;
                        $deleteError = null;
                        break;
                    } catch (Throwable $error) {
                        $deleteError = $error;
                        if ($attempt < 3) {
                            open_session([]);
                        }
                    }
                }
                if ($deleteError) {
                    $failed[] = [
                        'id' => $id,
                        'type' => media_type_name((int)($item['type'] ?? 1)),
                        'timestamp' => $item['date'] ?? null,
                        'error' => 'Downloaded but delete failed: ' . $deleteError->getMessage(),
                    ];
                }
            }
            $processed += 1;
            if ($progressCallback) {
                $progressCallback([
                    'stage' => 'download_and_delete',
                    'processed' => $processed,
                    'total' => $total,
                    'current_id' => $id,
                    'downloaded' => count($downloaded),
                    'skipped' => count($skipped),
                    'deleted' => count($deleted),
                    'failed' => count($failed),
                ]);
            }
            touch_camera_session_lease();
        }
        touch_camera_session_lease();
        invalidate_api_cache('bridge.status');
        invalidate_api_cache('media.gallery');
        return [
            'directory' => $dir,
            'downloaded' => count($downloaded),
            'skipped' => count($skipped),
            'deleted' => count($deleted),
            'failed' => count($failed),
            'files' => $downloaded,
            'errors' => $failed,
        ];
    });
}

function delete_all_media_from_camera(?callable $progressCallback = null, ?int $total = null): array
{
    return with_camera_lock(function () use ($progressCallback, $total): array {
        open_session([]);
        $deleted = [];
        $failed = [];
        $skipped = [];
        $processed = 0;
        $seenBatches = [];
        $batchIndex = 0;
        while (true) {
            $items = raw_gallery_items();
            $batchIds = [];
            foreach ($items as $item) {
                if (is_array($item) && !empty($item['id'])) {
                    $batchIds[] = (int)$item['id'];
                }
            }
            if (!$batchIds) {
                break;
            }
            $batchKey = implode(',', $batchIds);
            if (isset($seenBatches[$batchKey])) {
                break;
            }
            $seenBatches[$batchKey] = true;
            $batchIndex += 1;
            foreach ($items as $item) {
                if (!is_array($item) || empty($item['id'])) {
                    continue;
                }
                $id = (int)$item['id'];
                $ext = strtoupper(media_file_extension($item)) === 'MP4' ? 'mp4' : 'JPG';
                $lastError = null;
                $response = null;
                for ($attempt = 1; $attempt <= 3; $attempt += 1) {
                    try {
                        $response = camera_json('GET', "/cmd/delete/{$id}/{$ext}", null, false);
                        $lastError = null;
                        break;
                    } catch (Throwable $error) {
                        $lastError = $error;
                        if ($attempt < 3) {
                            open_session([]);
                            continue;
                        }
                    }
                }
                if (is_array($response) && (($response['code'] ?? 0) === -2 || str_contains(strtolower((string)($response['desc'] ?? '')), 'file does not exist'))) {
                    $skipped[] = [
                        'id' => $id,
                        'type' => media_type_name((int)($item['type'] ?? 1)),
                        'timestamp' => $item['date'] ?? null,
                        'status' => 'missing',
                        'response' => $response,
                    ];
                } elseif (is_array($response)) {
                    $deleted[] = $id;
                }

                if ($lastError instanceof Throwable) {
                    $failed[] = [
                        'id' => $id,
                        'type' => media_type_name((int)($item['type'] ?? 1)),
                        'timestamp' => $item['date'] ?? null,
                        'error' => $lastError instanceof ApiError ? $lastError->getMessage() : 'Delete failed.',
                        'details' => $lastError instanceof ApiError ? $lastError->details : $lastError->getMessage(),
                    ];
                }
                $processed += 1;
                if ($progressCallback) {
                    $progressCallback([
                        'stage' => 'deleting',
                        'batch' => $batchIndex,
                        'processed' => $processed,
                        'total' => $total,
                        'current_id' => $id,
                        'last_deleted_id' => $id,
                        'deleted' => count($deleted),
                        'skipped' => count($skipped),
                        'failed' => count($failed),
                    ]);
                }
            }
            touch_camera_session_lease();
        }
        touch_camera_session_lease();
        invalidate_api_cache('bridge.status', 'media.gallery');
        return [
            'deleted' => count($deleted),
            'ids' => $deleted,
            'skipped' => count($skipped),
            'skipped_items' => $skipped,
            'failed' => count($failed),
            'errors' => $failed,
        ];
    });
}

function bridge_raw_json(string $method, string $path, ?array $query = null, mixed $payload = null, int $timeout = 30): array
{
    $url = bridge_url($path);
    if ($query) {
        $query = array_filter($query, static fn($value) => $value !== null);
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
    }
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
    $headers = $body === null ? [] : ['Content-Type' => 'application/json'];
    $result = http_request($method, $url, $body, $headers, $timeout);
    $decoded = json_decode($result['body'], true);
    return ['status' => $result['status'], 'body' => is_array($decoded) ? $decoded : []];
}

function durable_media_job_state_path(): string
{
    return getenv('TRAILCAM_DURABLE_MEDIA_JOB_STATE_PATH') ?: DEFAULT_DURABLE_MEDIA_JOB_STATE_PATH;
}

function read_durable_media_job_state(): ?array
{
    $path = durable_media_job_state_path();
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode(file_get_contents($path) ?: '', true);
    return is_array($decoded) ? $decoded : null;
}

function write_durable_media_job_state(array $state): array
{
    @file_put_contents(durable_media_job_state_path(), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $state;
}

// The ESP32 owns this job once accepted: it keeps downloading (and, for the
// delete action, deleting) even if HaLow drops. A 409 means a job (possibly
// this one) is already running on the board, so we adopt it rather than
// treating it as a failure.
function start_durable_media_job(string $action): array
{
    if (!in_array($action, ['download_all_media', 'download_and_delete_media'], true)) {
        throw new ApiError('invalid_request', 'Unknown durable media job action.', 400, $action);
    }
    $response = bridge_raw_json('POST', '/jobs', ['action' => $action]);
    $body = $response['body'];
    $job = is_array($body['job'] ?? null) ? $body['job'] : null;
    // Some bridge revisions return the active job nested in `job`; others
    // return only the active `job_id` with the 409. Both mean the board has
    // accepted no new job because one is already in flight, so adopt it and
    // let the caller inspect its live status.
    $activeJobId = (string)($job['job_id'] ?? $body['job_id'] ?? '');
    if ($response['status'] === 409 && $activeJobId !== '') {
        return write_durable_media_job_state([
            'job_id' => $activeJobId,
            'action' => $job['action'] ?? $action,
            'adopted' => true,
            'last_status' => $job ?: ['job_id' => $activeJobId, 'status' => 'running'],
            'last_seen_at' => gmdate('c'),
            'connectivity' => 'online',
        ]);
    }
    if ($response['status'] >= 400 || empty($job['job_id'])) {
        throw new ApiError('camera_action_failed', 'Bridge rejected the durable media job request.', 502, $body);
    }
    return write_durable_media_job_state([
        'job_id' => $job['job_id'],
        'action' => $job['action'] ?? $action,
        'adopted' => false,
        'last_status' => $job,
        'last_seen_at' => gmdate('c'),
        'connectivity' => 'online',
    ]);
}

function fetch_trail_media_status(): array
{
    try {
        $status = bridge_json('GET', '/trail-media/status');
    } catch (ApiError $error) {
        if ($error->errorCode === 'bridge_unreachable') {
            return ['ready' => null, 'reachable' => false, 'job' => null];
        }
        throw $error;
    }
    $status = is_array($status) ? $status : [];
    $status['reachable'] = true;
    return $status;
}

// Reconciles our locally persisted job pointer against the board's live
// status. Loss of connectivity marks connectivity unknown without failing
// the job or submitting a replacement — the board keeps working on its own.
function reconcile_durable_media_job(): array
{
    $state = read_durable_media_job_state();
    $status = fetch_trail_media_status();
    if (empty($status['reachable'])) {
        if ($state) {
            $state['connectivity'] = 'unknown';
            write_durable_media_job_state($state);
        }
        return ['connectivity' => 'unknown', 'state' => $state, 'live' => null];
    }
    $liveJob = is_array($status['job'] ?? null) ? $status['job'] : null;
    if ($liveJob && !empty($liveJob['job_id'])) {
        if (!$state || (string)($state['job_id'] ?? '') !== (string)$liveJob['job_id']) {
            $state = [
                'job_id' => $liveJob['job_id'],
                'action' => $liveJob['action'] ?? ($state['action'] ?? null),
                'adopted' => true,
            ];
        }
        $state['last_status'] = $liveJob;
        $state['last_seen_at'] = gmdate('c');
        $state['connectivity'] = 'online';
        write_durable_media_job_state($state);
    } elseif ($state) {
        // The ESP32 does not retain completed/failed jobs across a reboot.
        // If it is reachable but reports no job, a previously running local
        // pointer can never make further progress.  Mark it terminal instead
        // of leaving the UI to poll an "idle" board forever.
        $previous = is_array($state['last_status'] ?? null) ? $state['last_status'] : [];
        $previousStatus = strtolower((string)($previous['status'] ?? ''));
        if (in_array($previousStatus, ['pending', 'queued', 'running'], true)) {
            $state['last_status'] = array_merge($previous, [
                'status' => 'failed',
                'stage' => 'job_lost',
                'error' => [
                    'message' => 'The ESP32 is online but no longer has this media job. It was likely interrupted or the board restarted.',
                ],
            ]);
        }
        // Do not let the board's placeholder {job_id: 0, status: "idle"}
        // mask the terminal state above in the browser.
        $status['job'] = null;
        $state['connectivity'] = 'online';
        write_durable_media_job_state($state);
    }
    return ['connectivity' => 'online', 'state' => $state, 'live' => $status];
}

function list_staged_trail_media_files(): array
{
    $result = bridge_json('GET', '/trail-media/files');
    $items = is_array($result['items'] ?? null) ? $result['items'] : [];
    return array_values(array_filter($items, fn($i) => is_array($i) && !empty($i['filename'])));
}

function staged_trail_media_dir(): string
{
    return ensure_pictures_dir();
}

function camera_profile_prefix(?string $fallback = null): string
{
    try {
        $data = bridge_json('GET', '/camera/target'); $active = $data['active'] ?? [];
        foreach ((array)($data['profiles'] ?? []) as $profile) if (is_array($profile) && strtolower((string)($profile['ble_mac'] ?? '')) === strtolower((string)($active['ble_mac'] ?? ''))) return preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$profile['id']) ?: 'camera';
    } catch (Throwable) {}
    return $fallback ?: 'camera';
}

function staged_trail_media_local_path(string $filename, ?string $profileId = null): string
{
    return staged_trail_media_dir() . '/' . camera_profile_prefix($profileId) . '_' . basename($filename);
}

function download_staged_trail_media_file(array $item, ?string $profileId = null): array
{
    $filename = (string)($item['filename'] ?? '');
    if ($filename === '') {
        return ['error' => 'Item is missing a filename.', 'item' => $item];
    }
    $expectedBytes = (int)($item['bytes'] ?? 0);
    $path = staged_trail_media_local_path($filename, $profileId);
    if (is_file($path) && $expectedBytes > 0 && filesize($path) === $expectedBytes) {
        return ['filename' => $filename, 'path' => $path, 'bytes' => filesize($path), 'skipped' => true];
    }
    $remotePath = (string)($item['url'] ?? ('/trail-media/file/' . rawurlencode($filename)));
    try {
        $result = http_request('GET', bridge_url($remotePath), null, [], 120);
    } catch (ApiError $error) {
        return ['filename' => $filename, 'error' => $error->getMessage(), 'details' => $error->details];
    }
    if ($result['status'] < 200 || $result['status'] >= 300) {
        return ['filename' => $filename, 'error' => 'Download failed.', 'details' => ['status' => $result['status']]];
    }
    $bytes = strlen($result['body']);
    if ($expectedBytes > 0 && $bytes !== $expectedBytes) {
        return ['filename' => $filename, 'error' => 'Byte count mismatch after download.', 'details' => ['expected' => $expectedBytes, 'received' => $bytes]];
    }
    $written = @file_put_contents($path, $result['body'], LOCK_EX);
    if ($written === false || $written !== $bytes) {
        return ['filename' => $filename, 'error' => 'Failed to persist downloaded file.', 'details' => ['path' => $path]];
    }
    return ['filename' => $filename, 'path' => $path, 'bytes' => $written];
}

// Pulls files already staged on the ESP32 SD card (via a completed durable
// job); it does not start or require a job itself. Locked so two concurrent
// pulls (e.g. a retried CLI run overlapping a UI-triggered one) can't race
// on writing the same destination file.
function pull_staged_trail_media(?callable $progressCallback = null, ?string $profileId = null): array
{
    $lockHandle = fopen(getenv('TRAILCAM_TRAIL_MEDIA_PULL_LOCK_PATH') ?: DEFAULT_TRAIL_MEDIA_PULL_LOCK_PATH, 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        throw new ApiError('internal_error', 'Failed to acquire trail media pull lock.', 500);
    }
    try {
        $items = list_staged_trail_media_files();
        $downloaded = [];
        $skipped = [];
        $failed = [];
        $total = count($items);
        $processed = 0;
        foreach ($items as $item) {
            $result = download_staged_trail_media_file($item, $profileId);
            $processed += 1;
            if (isset($result['error'])) {
                $failed[] = $result;
            } elseif (!empty($result['skipped'])) {
                $skipped[] = $result;
            } else {
                $downloaded[] = $result;
            }
            if ($progressCallback) {
                $progressCallback([
                    'stage' => 'pulling_staged_media',
                    'processed' => $processed,
                    'total' => $total,
                    'current_filename' => $item['filename'] ?? null,
                    'downloaded' => count($downloaded),
                    'skipped' => count($skipped),
                    'failed' => count($failed),
                ]);
            }
        }
        return [
            'directory' => staged_trail_media_dir(),
            'total' => $total,
            'downloaded' => count($downloaded),
            'skipped' => count($skipped),
            'failed' => count($failed),
            'files' => $downloaded,
            'skipped_files' => $skipped,
            'errors' => $failed,
        ];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function pull_completed_durable_trail_media(): array
{
    $result = pull_staged_trail_media();
    if (empty($result['failed'])) {
        $state = read_durable_media_job_state();
        if ($state) {
            $state['server_pull_completed_at'] = gmdate('c');
            write_durable_media_job_state($state);
        }
    }
    return $result;
}

function run_media_download_all_job(string $jobId): void
{
    $job = read_media_job($jobId);
    if (!$job) {
        return;
    }
    write_media_job($jobId, array_merge($job, ['status' => 'running', 'error' => null]));
    try {
        $result = download_all_media_to_server(function (array $progress) use ($jobId, $job): void {
            write_media_job($jobId, array_merge($job, [
                'status' => 'running',
                'error' => null,
                'progress' => $progress,
            ]));
        });
        write_media_job($jobId, array_merge($job, ['status' => 'completed', 'result' => $result, 'error' => null]));
    } catch (Throwable $error) {
        write_media_job($jobId, array_merge($job, [
            'status' => 'failed',
            'result' => null,
            'error' => [
                'message' => $error->getMessage(),
                'details' => $error instanceof ApiError ? $error->details : null,
            ],
        ]));
    }
}

function write_media_job_progress(string $jobId, array $progress): void
{
    $job = read_media_job($jobId);
    if (!$job) {
        return;
    }
    write_media_job($jobId, array_merge($job, [
        'status' => 'running',
        'error' => null,
        'progress' => $progress,
    ]));
}

function run_media_delete_all_job(string $jobId): void
{
    $job = read_media_job($jobId);
    if (!$job) {
        return;
    }
    write_media_job($jobId, array_merge($job, ['status' => 'running', 'error' => null]));
    try {
        $result = delete_all_media_from_camera(function (array $progress) use ($jobId, $job): void {
            write_media_job($jobId, array_merge($job, [
                'status' => 'running',
                'error' => null,
                'progress' => $progress,
            ]));
        });
        write_media_job($jobId, array_merge($job, ['status' => 'completed', 'result' => $result, 'error' => null]));
    } catch (Throwable $error) {
        write_media_job($jobId, array_merge($job, [
            'status' => 'failed',
            'result' => null,
            'error' => [
                'message' => $error->getMessage(),
                'details' => $error instanceof ApiError ? $error->details : null,
            ],
        ]));
    }
}

function run_media_download_and_delete_job(string $jobId): void
{
    $job = read_media_job($jobId);
    if (!$job) {
        return;
    }
    write_media_job($jobId, array_merge($job, ['status' => 'running', 'error' => null]));
    try {
        $result = download_and_delete_media(function (array $progress) use ($jobId, $job): void {
            write_media_job($jobId, array_merge($job, [
                'status' => 'running',
                'error' => null,
                'progress' => $progress,
            ]));
        });
        write_media_job($jobId, array_merge($job, ['status' => 'completed', 'result' => $result, 'error' => null]));
    } catch (Throwable $error) {
        write_media_job($jobId, array_merge($job, [
            'status' => 'failed',
            'result' => null,
            'error' => [
                'message' => $error->getMessage(),
                'details' => $error instanceof ApiError ? $error->details : null,
            ],
        ]));
    }
}

function media_camera_path(int $id, bool $thumb = false): string
{
    $items = list_media_items();
    foreach ($items as $item) {
        if ((int)$item['id'] === $id) {
            $rawType = (int)($item['raw']['type'] ?? 1);
            if ($thumb) {
                return "/thumb/{$id}/JPG";
            }
            return $rawType === 2 ? "/file/{$id}/mp4" : "/file/{$id}/JPG";
        }
    }
    throw new ApiError('not_found', 'Media item was not found.', 404);
}

function video_thumb_path(int $id, string $videoPath): string
{
    // Camera media IDs can be reused. Key thumbnails to the exact server file
    // so a prior recording with the same ID cannot supply the wrong preview.
    $identity = $videoPath . ':' . ((string)@filesize($videoPath)) . ':' . ((string)@filemtime($videoPath));
    return ensure_pictures_dir() . '/thumbs/' . $id . '-' . substr(sha1($identity), 0, 16) . '.jpg';
}

function generate_video_thumb(int $id, string $videoPath): ?string
{
    $thumbPath = video_thumb_path($id, $videoPath);
    $thumbDir = dirname($thumbPath);
    if (!is_dir($thumbDir) && !@mkdir($thumbDir, 0775, true) && !is_dir($thumbDir)) {
        return null;
    }
    if (is_file($thumbPath) && is_readable($thumbPath) && filesize($thumbPath) > 0) {
        return $thumbPath;
    }
    $cmd = sprintf(
        'ffmpeg -y -i %s -ss 00:00:01 -vframes 1 -vf "scale=320:-1" %s 2>/dev/null',
        escapeshellarg($videoPath),
        escapeshellarg($thumbPath)
    );
    exec($cmd, $output, $exitCode);
    if ($exitCode !== 0 || !is_file($thumbPath) || filesize($thumbPath) === 0) {
        // Try at 0s if the video is shorter than 1s
        $cmd = sprintf(
            'ffmpeg -y -i %s -vframes 1 -vf "scale=320:-1" %s 2>/dev/null',
            escapeshellarg($videoPath),
            escapeshellarg($thumbPath)
        );
        exec($cmd, $output, $exitCode);
    }
    return (is_file($thumbPath) && filesize($thumbPath) > 0) ? $thumbPath : null;
}

function local_media_file_path(int $id, bool $thumb = false): ?string
{
    $dir = ensure_pictures_dir();
    $matches = array_merge(
        glob($dir . '/trailcam_*_' . $id . '.*') ?: [],
        glob($dir . '/' . $id . '.*') ?: []
    );
    if (!$matches) {
        return null;
    }
    foreach ($matches as $path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($thumb && $ext !== 'jpg' && $ext !== 'jpeg') {
            continue;
        }
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

// True only when we have positive evidence (a recent cached camera gallery
// listing) that this id is not on the camera. A cold/absent cache means we
// don't know either way, so callers should fall back to checking the camera
// rather than assume server-only.
function media_item_is_server_only(int $id): bool
{
    // The gallery contains server media only, so any listed local file is
    // safe to remove without contacting the trail camera.
    return local_media_file_path($id) !== null;
}

function delete_local_media(int $id): bool
{
    $path = local_media_file_path($id);
    if (!$path || !@unlink($path)) {
        return false;
    }
    $dir = ensure_pictures_dir() . '/thumbs';
    foreach (glob($dir . '/' . $id . '*.jpg') ?: [] as $thumb) {
        @unlink($thumb);
    }
    return true;
}

function pushed_media_file_path(int $id): ?string
{
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT blob_path FROM board_media WHERE id=?');
    $stmt->execute([$id]);
    $path = $stmt->fetchColumn();
    if (!is_string($path) || $path === '') {
        return null;
    }
    $realPath = realpath($path);
    $storageDir = realpath(media_storage_dir());
    if (!$realPath || !$storageDir || !str_starts_with($realPath, rtrim($storageDir, '/') . '/') || !is_file($realPath) || !is_readable($realPath)) {
        return null;
    }
    return $realPath;
}

function proxy_pushed_media_file(int $id, bool $thumb): never
{
    $path = pushed_media_file_path($id);
    if (!$path) {
        throw new ApiError('not_found', 'ESP32-uploaded media item was not found.', 404);
    }
    $isVideo = str_ends_with(strtolower($path), '.mp4');
    if ($thumb && $isVideo) {
        $generated = generate_video_thumb($id, $path);
        if (!$generated) {
            throw new ApiError('thumbnail_failed', 'Failed to generate the ESP32 video thumbnail.', 500, ['media_id' => $id]);
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($generated));
        readfile($generated);
        exit;
    }
    header('Content-Type: ' . ($thumb ? 'image/jpeg' : ($isVideo ? 'video/mp4' : 'image/jpeg')));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function delete_pushed_media(int $id): bool
{
    $path = pushed_media_file_path($id);
    if (!$path) {
        return false;
    }
    $thumbPath = video_thumb_path($id, $path);
    if (!@unlink($path)) {
        return false;
    }
    @unlink($thumbPath);
    db()->prepare('UPDATE board_media SET blob_path=NULL, blob_bytes=NULL, blob_sha256=NULL WHERE id=?')->execute([$id]);
    return true;
}

function delete_gallery_media(string $id): bool
{
    if (preg_match('/^push-(\d+)$/', $id, $m)) {
        return delete_pushed_media((int)$m[1]);
    }
    return ctype_digit($id) && delete_local_media((int)$id);
}

function proxy_camera_file(int $id, bool $thumb): never
{
    $local = local_media_file_path($id, false);
    if ($thumb && $local && str_ends_with(strtolower($local), '.mp4')) {
        $generated = generate_video_thumb($id, $local);
        if ($generated) {
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . filesize($generated));
            readfile($generated);
            exit;
        }
        throw new ApiError('thumbnail_failed', 'Failed to generate the local video thumbnail.', 500, ['media_id' => $id]);
    }
    if ($local) {
        $contentType = $thumb ? 'image/jpeg' : (str_ends_with(strtolower($local), '.mp4') ? 'video/mp4' : 'image/jpeg');
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($local));
        readfile($local);
        exit;
    }

    $cameraPath = media_camera_path($id, $thumb);
    $result = http_request('GET', bridge_url('/camera/raw?' . http_build_query(['path' => $cameraPath])), null, [], 60);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new ApiError('camera_action_failed', 'Camera media download failed.', 502, $result['status']);
    }
    $contentType = $thumb || str_ends_with(strtolower($cameraPath), 'jpg') ? 'image/jpeg' : 'video/mp4';
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($result['body']));
    echo $result['body'];
    exit;
}

function handle_observations_upload(array $body): void
{
    $scannerHost = (string)($body['scanner_host'] ?? '');
    $observations = $body['observations'] ?? null;
    if ($scannerHost === '' || !is_array($observations)) {
        throw new ApiError('invalid_request', 'scanner_host and observations are required.');
    }
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO rf_observations
        (scanner_host, scanner_mac, scanner_health, mac, device_type, interface_name, signal_dbm, channel, freq_mhz, ssid, ht, vht, he, probe_count, manufacturer_data, adv_services, adv_service_data, tx_power, tracker_type, recorded_at)
        VALUES
        (:scanner_host, :scanner_mac, :scanner_health, :mac, :device_type, :interface_name, :signal_dbm, :channel, :freq_mhz, :ssid, :ht, :vht, :he, :probe_count, :manufacturer_data, :adv_services, :adv_service_data, :tx_power, :tracker_type, :recorded_at)
    ");
    $inserted = 0;
    $devices = [];
    foreach ($observations as $obs) {
        if (!is_array($obs) || empty($obs['mac'])) {
            continue;
        }
        $stmt->execute([
            ':scanner_host' => $scannerHost,
            ':scanner_mac' => $body['health']['mac'] ?? null,
            ':scanner_health' => json_col($body['health'] ?? null),
            ':mac' => (string)$obs['mac'],
            ':device_type' => (string)($obs['device_type'] ?? 'AP'),
            ':interface_name' => $obs['interface'] ?? null,
            ':signal_dbm' => $obs['signal_dbm'] ?? null,
            ':channel' => $obs['channel'] ?? null,
            ':freq_mhz' => $obs['freq_mhz'] ?? null,
            ':ssid' => $obs['ssid'] ?? null,
            ':ht' => isset($obs['ht']) ? (int)(bool)$obs['ht'] : null,
            ':vht' => isset($obs['vht']) ? (int)(bool)$obs['vht'] : null,
            ':he' => isset($obs['he']) ? (int)(bool)$obs['he'] : null,
            ':probe_count' => $obs['probe_count'] ?? null,
            ':manufacturer_data' => $obs['manufacturer_data'] ?? null,
            ':adv_services' => $obs['adv_services'] ?? null,
            ':adv_service_data' => $obs['adv_service_data'] ?? null,
            ':tx_power' => $obs['tx_power'] ?? null,
            ':tracker_type' => $obs['tracker_type'] ?? null,
            ':recorded_at' => sql_datetime($obs['recorded_at'] ?? null),
        ]);
        $inserted++;
        $devices[strtolower((string)$obs['mac'])] = true;
    }
    json_response(['inserted' => $inserted, 'devices' => count($devices)], 200, false);
}

function handle_board_telemetry(array $body): void
{
    if (empty($body['scanner_host'])) {
        throw new ApiError('invalid_request', 'scanner_host is required.');
    }
    if (!isset($body['temperature']) && isset($body['environment']) && is_array($body['environment'])) {
        $body['temperature'] = $body['environment'];
    }
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO board_telemetry (scanner_host, recorded_at, firmware, board, battery, temperature, radio, payload)
        VALUES (:scanner_host, :recorded_at, :firmware, :board, :battery, :temperature, :radio, :payload)
        ON DUPLICATE KEY UPDATE firmware=VALUES(firmware), board=VALUES(board), battery=VALUES(battery), temperature=VALUES(temperature), radio=VALUES(radio), payload=VALUES(payload)
    ");
    $stmt->execute([
        ':scanner_host' => $body['scanner_host'],
        ':recorded_at' => sql_datetime($body['recorded_at'] ?? null),
        ':firmware' => json_col($body['firmware'] ?? null),
        ':board' => json_col($body['board'] ?? null),
        ':battery' => json_col($body['battery'] ?? null),
        ':temperature' => json_col($body['temperature'] ?? null),
        ':radio' => json_col($body['radio'] ?? null),
        ':payload' => json_col($body),
    ]);
    $id = (int)$pdo->lastInsertId();
    if ($id === 0) {
        $id = (int)$pdo->query("SELECT id FROM board_telemetry WHERE scanner_host=" . $pdo->quote((string)$body['scanner_host']) . " AND recorded_at=" . $pdo->quote(sql_datetime($body['recorded_at'] ?? null)))->fetchColumn();
    }
    json_response(['ok' => true, 'telemetry_id' => $id], 200, false);
}

function normalize_telemetry_row(array $row): array
{
    $temperature = json_decode_array($row['temperature'] ?? null);
    $battery = json_decode_array($row['battery'] ?? null);
    $board = json_decode_array($row['board'] ?? null);
    $radio = json_decode_array($row['radio'] ?? null);
    $payload = json_decode_array($row['payload'] ?? null);

    $temperatureC = first_numeric($temperature, ['temperature_c', 'temp_c', 'dht11_temperature_c']);
    $humidity = first_numeric($temperature, ['humidity_percent', 'humidity_rh', 'relative_humidity_percent', 'relative_humidity', 'dht11_humidity_percent']);

    return [
        'telemetry_id' => (int)$row['id'],
        'scanner_host' => $row['scanner_host'],
        'recorded_at' => $row['recorded_at'],
        'sensor' => $temperature['sensor'] ?? null,
        'probe_attached' => $temperature['probe_attached'] ?? ($temperatureC !== null || $humidity !== null),
        'temperature_c' => $temperatureC,
        'temperature_f' => $temperatureC === null ? null : ($temperatureC * 9 / 5) + 32,
        'humidity_percent' => $humidity,
        'battery' => $battery,
        'board' => $board,
        'radio' => $radio,
        'temperature' => $temperature,
        'payload' => $payload,
    ];
}

function latest_board_telemetry(?string $scannerHost = null): ?array
{
    $pdo = db();
    if ($scannerHost !== null && $scannerHost !== '') {
        $stmt = $pdo->prepare('SELECT * FROM board_telemetry WHERE scanner_host = ? ORDER BY recorded_at DESC, id DESC LIMIT 1');
        $stmt->execute([$scannerHost]);
    } else {
        $stmt = $pdo->query('SELECT * FROM board_telemetry ORDER BY recorded_at DESC, id DESC LIMIT 1');
    }
    $row = $stmt->fetch();
    return $row ? normalize_telemetry_row($row) : null;
}

function media_storage_dir(): string
{
    $candidates = [];

    $configured = getenv('TRAILCAM_MEDIA_DIR') ?: '';
    if ($configured !== '') {
        $candidates[] = $configured;
    }

    $candidates[] = DEFAULT_MEDIA_DIR;
    $candidates[] = rtrim(sys_get_temp_dir(), '/') . '/trail_cam_media';

    foreach ($candidates as $dir) {
        $dir = rtrim($dir, '/');
        if ($dir === '') {
            continue;
        }

        if (is_dir($dir)) {
            if (is_writable($dir)) {
                return $dir;
            }
            continue;
        }

        $parent = dirname($dir);
        if (!is_dir($parent) || !is_writable($parent)) {
            continue;
        }

        if (@mkdir($dir, 0775, true) || is_dir($dir)) {
            if (is_writable($dir)) {
                return $dir;
            }
        }
    }

    throw new ApiError('internal_error', 'No writable media storage directory is available.', 500, $candidates);
}

function handle_board_media(array $body): void
{
    foreach (['scanner_host', 'source', 'media_type', 'filename', 'recorded_at'] as $key) {
        if (empty($body[$key])) {
            throw new ApiError('invalid_request', "{$key} is required.");
        }
    }
    if (!in_array($body['source'], ['onboard', 'trail_camera'], true)) {
        throw new ApiError('invalid_request', 'source must be onboard or trail_camera.');
    }
    if (!in_array($body['media_type'], ['image/jpeg', 'video/mp4'], true)) {
        throw new ApiError('invalid_request', 'media_type must be image/jpeg or video/mp4.');
    }
    $sha256 = strtolower(trim((string)($body['sha256'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
        throw new ApiError('invalid_request', 'sha256 is required and must be a 64-character lowercase or uppercase hex string.');
    }
    if (array_key_exists('bytes', $body) && (!is_numeric($body['bytes']) || (int)$body['bytes'] < 0)) {
        throw new ApiError('invalid_request', 'bytes must be a non-negative integer when provided.');
    }
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO board_media
        (scanner_host, source, media_type, filename, recorded_at, expected_bytes, sha256, metadata, original_media_id, original_media_type, camera_path, capture_kind)
        VALUES (:scanner_host, :source, :media_type, :filename, :recorded_at, :expected_bytes, :sha256, :metadata, :original_media_id, :original_media_type, :camera_path, :capture_kind)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), expected_bytes=VALUES(expected_bytes), metadata=VALUES(metadata)
    ");
    $stmt->execute([
        ':scanner_host' => $body['scanner_host'],
        ':source' => $body['source'],
        ':media_type' => $body['media_type'],
        ':filename' => basename((string)$body['filename']),
        ':recorded_at' => sql_datetime($body['recorded_at']),
        ':expected_bytes' => array_key_exists('bytes', $body) ? (int)$body['bytes'] : null,
        ':sha256' => $sha256,
        ':metadata' => json_col($body['metadata'] ?? null),
        ':original_media_id' => $body['original_media_id'] ?? null,
        ':original_media_type' => $body['original_media_type'] ?? null,
        ':camera_path' => $body['camera_path'] ?? null,
        ':capture_kind' => $body['capture_kind'] ?? null,
    ]);
    $id = (int)$pdo->lastInsertId();
    json_response(['ok' => true, 'media_id' => $id, 'upload_url' => public_api_base() . "/board/media/{$id}/blob"], 200, false);
}

function handle_media_blob(int $id): void
{
    $pdo = db();
    $media = $pdo->prepare('SELECT * FROM board_media WHERE id = ?');
    $media->execute([$id]);
    $row = $media->fetch();
    if (!$row) {
        throw new ApiError('not_found', 'Media metadata was not found.', 404);
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if ($contentType && $contentType !== $row['media_type']) {
        throw new ApiError('invalid_request', 'Content-Type does not match media metadata.');
    }
    $bytes = file_get_contents('php://input') ?: '';
    if ($row['expected_bytes'] !== null && (int)$row['expected_bytes'] !== strlen($bytes)) {
        throw new ApiError('invalid_request', 'Content length does not match media metadata.', 400, [
            'expected_bytes' => (int)$row['expected_bytes'],
            'actual_bytes' => strlen($bytes),
        ]);
    }
    $sha = hash('sha256', $bytes);
    $expectedSha = $row['sha256'] ?: ($_SERVER['HTTP_X_SHA256'] ?? '');
    if ($expectedSha && !hash_equals(strtolower((string)$expectedSha), strtolower($sha))) {
        throw new ApiError('invalid_request', 'SHA256 mismatch.');
    }

    $storageDir = media_storage_dir();
    $ext = $row['media_type'] === 'video/mp4' ? 'mp4' : 'jpg';
    $path = $storageDir . '/' . camera_profile_prefix() . "_{$id}.{$ext}";
    $written = @file_put_contents($path, $bytes, LOCK_EX);
    if ($written === false || $written !== strlen($bytes) || !is_file($path)) {
        throw new ApiError('internal_error', 'Failed to persist uploaded media blob.', 500, [
            'path' => $path,
            'expected_bytes' => strlen($bytes),
            'written_bytes' => $written,
        ]);
    }
    $stmt = $pdo->prepare('UPDATE board_media SET blob_path=?, blob_bytes=?, blob_sha256=?, uploaded_at=UTC_TIMESTAMP() WHERE id=?');
    $stmt->execute([$path, $written, $sha, $id]);
    json_response(['ok' => true, 'media_id' => $id, 'bytes' => $written], 200, false);
}

function handle_board_events(array $body): void
{
    if (empty($body['scanner_host']) || !is_array($body['events'] ?? null)) {
        throw new ApiError('invalid_request', 'scanner_host and events are required.');
    }
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO board_events (scanner_host, recorded_at, event_type, ok, reason, details, payload) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $inserted = 0;
    foreach ($body['events'] as $event) {
        if (!is_array($event) || empty($event['type'])) {
            continue;
        }
        $stmt->execute([
            $body['scanner_host'],
            sql_datetime($event['recorded_at'] ?? $body['recorded_at'] ?? null),
            $event['type'],
            isset($event['ok']) ? (int)(bool)$event['ok'] : null,
            $event['reason'] ?? null,
            json_col($event['details'] ?? null),
            json_col($event),
        ]);
        $inserted++;
    }
    json_response(['ok' => true, 'inserted' => $inserted], 200, false);
}

function camconn_dt(mixed $value): ?string
{
    return ($value === null || $value === '') ? null : sql_datetime((string)$value);
}

function camconn_int(mixed $value): ?int
{
    return ($value === null || $value === '' || !is_numeric($value)) ? null : (int)round((float)$value);
}

function camconn_num(mixed $value): ?float
{
    return ($value === null || $value === '' || !is_numeric($value)) ? null : (float)$value;
}

function camconn_str(mixed $value, int $max): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    return camconn_truncate((string)$value, $max);
}

function camconn_truncate(string $value, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

/**
 * POST /api/board/camera-connections
 * Body: {"scanner_host": "...", "connections": [ {...}, ... ]}
 * Retry-safe: (scanner_host, camera, started_at) identifies an attempt.
 */
function handle_camera_connections(array $body): void
{
    $host = $body['scanner_host'] ?? null;
    $rows = $body['connections'] ?? null;
    if (empty($host) || !is_array($rows)) {
        throw new ApiError('invalid_request', 'scanner_host and connections are required.');
    }

    $cols = [
        'scanner_host', 'camera', 'job_id', 'started_at', 'connected_at', 'ended_at',
        'ok', 'failure_stage', 'failure_message', 'wake_method', 'wake_attempts',
        'ble_rssi_dbm', 'wifi_rssi_dbm', 'wifi_rssi_min', 'wifi_rssi_avg', 'wifi_rssi_max',
        'wifi_freq_mhz', 'wifi_tx_bitrate', 'wifi_rx_bitrate',
        'ble_scan_ms', 'ble_connect_ms', 'hotspot_wait_ms', 'wifi_join_ms', 'total_connect_ms',
        'link_losses', 'items_downloaded', 'bytes_downloaded', 'avg_kbps',
        'details', 'payload',
    ];
    $keyCols = ['scanner_host', 'camera', 'started_at'];
    $updates = [];
    foreach ($cols as $column) {
        if (in_array($column, $keyCols, true)) {
            continue;
        }
        // A later session update may omit fields captured during connection setup.
        $updates[] = in_array($column, ['ok', 'payload'], true)
            ? "$column = VALUES($column)"
            : "$column = COALESCE(VALUES($column), $column)";
    }
    $sql = 'INSERT INTO camera_connections (' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE '
        . 'id = LAST_INSERT_ID(id), ' . implode(', ', $updates);

    $pdo = db();
    $stmt = $pdo->prepare($sql);
    $ids = [];
    $skipped = 0;
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['camera']) || empty($row['started_at']) || !array_key_exists('ok', $row)) {
            $skipped++;
            continue;
        }
        $stmt->execute([
            camconn_truncate((string)$host, 128),
            camconn_truncate((string)$row['camera'], 64),
            camconn_str($row['job_id'] ?? null, 128),
            sql_datetime((string)$row['started_at']),
            camconn_dt($row['connected_at'] ?? null),
            camconn_dt($row['ended_at'] ?? null),
            (int)(bool)$row['ok'],
            camconn_str($row['failure_stage'] ?? null, 64),
            camconn_str($row['failure_message'] ?? null, 255),
            camconn_str($row['wake_method'] ?? null, 32),
            camconn_int($row['wake_attempts'] ?? null),
            camconn_int($row['ble_rssi_dbm'] ?? null),
            camconn_int($row['wifi_rssi_dbm'] ?? null),
            camconn_int($row['wifi_rssi_min'] ?? null),
            camconn_num($row['wifi_rssi_avg'] ?? null),
            camconn_int($row['wifi_rssi_max'] ?? null),
            camconn_int($row['wifi_freq_mhz'] ?? null),
            camconn_str($row['wifi_tx_bitrate'] ?? null, 64),
            camconn_str($row['wifi_rx_bitrate'] ?? null, 64),
            camconn_int($row['ble_scan_ms'] ?? null),
            camconn_int($row['ble_connect_ms'] ?? null),
            camconn_int($row['hotspot_wait_ms'] ?? null),
            camconn_int($row['wifi_join_ms'] ?? null),
            camconn_int($row['total_connect_ms'] ?? null),
            camconn_int($row['link_losses'] ?? null),
            camconn_int($row['items_downloaded'] ?? null),
            camconn_int($row['bytes_downloaded'] ?? null),
            camconn_num($row['avg_kbps'] ?? null),
            isset($row['details']) ? json_col($row['details']) : null,
            json_col($row),
        ]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    json_response(['ok' => true, 'saved' => count($ids), 'skipped' => $skipped, 'ids' => $ids], 200, false);
}

/**
 * GET /api/board/camera-connections?camera=e6_plus&scanner_host=...&since=2026-11-01&ok=0&limit=200
 */
function list_camera_connections(array $query): array
{
    $where = [];
    $args = [];
    foreach (['camera', 'scanner_host'] as $field) {
        if (!empty($query[$field])) {
            $where[] = "$field = ?";
            $args[] = (string)$query[$field];
        }
    }
    if (!empty($query['since'])) {
        $where[] = 'started_at >= ?';
        $args[] = sql_datetime((string)$query['since']);
    }
    if (isset($query['ok']) && $query['ok'] !== '') {
        $where[] = 'ok = ?';
        $args[] = (int)(bool)$query['ok'];
    }
    $limit = max(1, min(1000, (int)($query['limit'] ?? 200)));
    $sql = 'SELECT id, scanner_host, camera, job_id, started_at, connected_at, ended_at, ok, failure_stage,
                   failure_message, wake_method, wake_attempts, ble_rssi_dbm, wifi_rssi_dbm, wifi_rssi_min,
                   wifi_rssi_avg, wifi_rssi_max, wifi_freq_mhz, wifi_tx_bitrate, wifi_rx_bitrate,
                   ble_scan_ms, ble_connect_ms, hotspot_wait_ms, wifi_join_ms, total_connect_ms,
                   link_losses, items_downloaded, bytes_downloaded, avg_kbps, details
            FROM camera_connections'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY started_at DESC LIMIT $limit";
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['details'] = $row['details'] !== null ? json_decode($row['details'], true) : null;
    }
    unset($row);
    return $rows;
}

function render_ui(string $view = 'trail'): never
{
    header('Content-Type: text/html; charset=utf-8');
    $api = htmlspecialchars(public_api_base(), ENT_QUOTES, 'UTF-8');
    $base = htmlspecialchars(public_mount_base(), ENT_QUOTES, 'UTF-8');
    $jsVer = filemtime(__DIR__ . '/app.js') ?: time();
    $view = $view === 'onboard' ? 'onboard' : 'trail';
    $trailHref = htmlspecialchars(($base ?: '') . '/trail', ENT_QUOTES, 'UTF-8');
    $onboardHref = htmlspecialchars(($base ?: '') . '/onboard', ENT_QUOTES, 'UTF-8');
    $title = $view === 'onboard' ? 'ESP32 Onboard Camera' : 'Trail Camera Bridge';
    $subtitle = $view === 'onboard'
        ? 'Capture, tune, and schedule the ESP32 onboard camera.'
        : 'Connect to the trail camera, manage sessions, and pull media.';
    $boardSectionTitle = $view === 'onboard' ? 'Board Health' : 'Board Status';
    $activityTitle = $view === 'onboard' ? 'Board Activity' : 'Trail Activity';
    $onboardSectionTitle = $view === 'onboard' ? 'Camera' : 'Onboard Camera';
    $titleEscaped = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $subtitleEscaped = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    $boardSectionTitleEscaped = htmlspecialchars($boardSectionTitle, ENT_QUOTES, 'UTF-8');
    $activityTitleEscaped = htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8');
    $onboardSectionTitleEscaped = htmlspecialchars($onboardSectionTitle, ENT_QUOTES, 'UTF-8');
    $trailNavClass = $view === 'trail' ? 'button-link nav-link active' : 'button-link nav-link';
    $onboardNavClass = $view === 'onboard' ? 'button-link nav-link active' : 'button-link nav-link';
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$titleEscaped}</title>
    <link rel="stylesheet" href="{$base}/app.css">
</head>
<body><script>document.body.classList.toggle('dark-mode', localStorage.getItem('trail-media-dark') === '1');</script>
    <main class="app" data-api-base="{$api}" data-view="{$view}">
        <header class="topbar">
            <div>
                <h1>{$titleEscaped}</h1>
                <div class="subline">{$subtitleEscaped}</div>
                <div class="subline" id="apiBase">{$api}</div>
            </div>
            <div class="top-actions">
                <nav class="view-nav" aria-label="Camera views">
                    <a class="{$trailNavClass}" href="{$trailHref}">Trail Camera</a>
                    <a href="{$base}/media">Media</a>
                    <a class="{$onboardNavClass}" href="{$onboardHref}">Onboard Camera</a>
                </nav>
                <span class="pill" id="bridgePill">Bridge unknown</span>
                <button id="themeToggle" title="Toggle dark mode">Dark mode</button>
                <button class="icon-button" id="refreshAll" title="Refresh all" aria-label="Refresh all">↻</button>
            </div>
        </header>

        <section class="band status-band trail-only">
            <div class="metric">
                <span>Camera</span>
                <strong id="cameraState">Unknown</strong>
            </div>
            <div class="metric">
                <span>Session</span>
                <strong id="sessionState">Unknown</strong>
            </div>
            <div class="metric">
                <span>Live</span>
                <strong id="liveState">Unknown</strong>
            </div>
            <div class="metric">
                <span>Bridge IP</span>
                <strong id="bridgeIp">-</strong>
            </div>
        </section>

        <section class="band activity-band">
            <div class="activity-header">
                <div>
                    <span class="activity-label">{$activityTitleEscaped}</span>
                    <strong id="cameraActivityState">Idle</strong>
                </div>
                <div class="activity-timer" id="cameraActivityTimer">0.0s</div>
            </div>
            <div class="activity-body">
                <div id="cameraActivityMessage">No camera action running.</div>
                <div class="activity-meta">
                    <span id="cameraActivityLast">Last result: none</span>
                    <span id="cameraActivityDetail">Ready</span>
                </div>
            </div>
        </section>

        <section class="wide-section trail-only workflow-section">
            <div class="section-head">
                <h2>Session Workflow</h2>
            </div>
            <div class="workflow-strip">
                <div class="workflow-step">
                    <span>1</span>
                    <strong>Connect</strong>
                    <small>Wake and join the trail camera</small>
                </div>
                <div class="workflow-step">
                    <span>2</span>
                    <strong>Live View</strong>
                    <small>Verify framing and session health</small>
                </div>
                <div class="workflow-step">
                    <span>3</span>
                    <strong>Capture</strong>
                    <small>Take a photo or start recording</small>
                </div>
                <div class="workflow-step">
                    <span>4</span>
                    <strong>Disconnect</strong>
                    <small>Return the camera to standby</small>
                </div>
            </div>
        </section>

        <section class="workspace trail-only trail-workspace">
            <section class="panel live-panel">
                <div class="panel-head">
                    <h2>Live View</h2>
                    <button class="text-button" id="refreshLive">Refresh</button>
                </div>
                <div class="preview live-preview" id="livePreview">
                    <img id="liveFrame" alt="Live preview" hidden>
                    <div class="live-preview-fallback" id="liveFallback">
                        <strong id="streamTitle">Stream inactive</strong>
                        <span id="streamMeta">No player target</span>
                    </div>
                </div>
                <div class="button-row">
                    <button class="primary" id="startLiveBtn">Start Live</button>
                    <button id="stopLiveBtn">Stop Live</button>
                    <a class="button-link" id="playerLink" href="#" target="_blank" rel="noreferrer">Open Target</a>
                </div>
            </section>
            <div class="trail-sidebar">
                <section class="panel connection-panel">
                    <div class="panel-head">
                        <h2>Connection</h2>
                        <button class="text-button" id="refreshStatus">Refresh</button>
                    </div>
                    <div class="button-row">
                        <button class="primary" id="connectBtn">Connect</button>
                        <button id="disconnectBtn">Disconnect</button>
                    </div>
                    <dl class="detail-grid">
                        <div><dt>WiFi</dt><dd id="wifiState">-</dd></div>
                        <div><dt>HaLow</dt><dd id="halowState">-</dd></div>
                        <div><dt>Bridge</dt><dd id="bridgeInfo">-</dd></div>
                        <div><dt>Camera IP</dt><dd id="cameraIp">-</dd></div>
                        <div><dt>BLE Stage</dt><dd id="bleStage">-</dd></div>
                        <div><dt>Standby</dt><dd id="standbyState">-</dd></div>
                        <div><dt>Last Message</dt><dd id="lastMessage">-</dd></div>
                    </dl>
                    <div class="panel-subhead">
                        <h3>Camera Target</h3>
                        <button class="text-button" id="refreshTargetBtn">Refresh</button>
                    </div>
                    <div class="target-active quiet" id="cameraTargetActive">-</div>
                    <div class="button-row target-row">
                        <select id="cameraTargetSelect"><option value="">Loading…</option></select>
                        <button id="switchCameraBtn">Switch</button>
                    </div>
                </section>

                <section class="panel actions-panel">
                    <div class="panel-head">
                        <h2>Capture</h2>
                        <span class="quiet" id="actionState">Ready</span>
                    </div>
                    <div class="button-row">
                        <button class="primary" id="takePictureBtn">Capture Photo</button>
                        <button id="videoStartBtn">Start Recording</button>
                        <button id="videoStopBtn">Stop Recording</button>
                    </div>
                    <div class="clock-row">
                        <input id="clockInput" type="datetime-local">
                        <button id="setClockBtn">Set Clock</button>
                        <button class="text-button" id="readClockBtn">Read</button>
                    </div>
                </section>
            </div>
        </section>

        <section class="wide-section board-section">
            <div class="section-head">
                <h2>{$boardSectionTitleEscaped}</h2>
                <button class="text-button" id="refreshBoardStatus">Refresh</button>
            </div>
            <section class="panel board-panel">
                <div class="summary-grid">
                    <div class="summary-card"><span>Recorded</span><strong id="boardRecordedAt">-</strong></div>
                    <div class="summary-card"><span>Host</span><strong id="boardHost">-</strong></div>
                    <div class="summary-card"><span>Battery</span><strong id="boardBattery">-</strong></div>
                    <div class="summary-card"><span>BLE</span><strong id="boardBleStage">-</strong></div>
                    <div class="summary-card"><span>Last Bringup</span><strong id="boardBringupMs">-</strong></div>
                    <div class="summary-card"><span>Lease</span><strong id="boardLeaseState">-</strong></div>
                    <div class="summary-card"><span>Temperature</span><strong id="tempState">-</strong></div>
                    <div class="summary-card"><span>Humidity</span><strong id="humidityState">-</strong></div>
                </div>
                <details class="diag-details">
                    <summary>Diagnostics JSON</summary>
                    <pre class="json-card" id="boardStatusRaw">No board snapshot loaded.</pre>
                </details>
            </section>
        </section>

        <section class="wide-section onboard-section onboard-only">
            <div class="section-head">
                <h2>{$onboardSectionTitleEscaped}</h2>
                <div class="button-row compact">
                    <button type="button" id="refreshOnboardBtn">Refresh</button>
                    <button type="button" class="primary" id="captureOnboardBtn">Capture Now</button>
                    <button type="button" id="syncOnboardClockBtn">Sync Clock</button>
                </div>
            </div>
            <div class="onboard-grid">
                <section class="panel">
                    <div class="preview onboard-preview">
                        <img id="onboardLatestFrame" alt="Latest onboard capture" hidden>
                        <div class="live-preview-fallback" id="onboardLatestFallback">
                            <strong id="onboardPreviewTitle">No onboard image</strong>
                            <span id="onboardPreviewMeta">Capture a frame to populate the latest JPEG.</span>
                        </div>
                    </div>
                    <div class="button-row compact onboard-preview-actions">
                        <button type="button" id="refreshOnboardImageBtn">Refresh Image</button>
                        <a class="button-link" id="openOnboardImageLink" href="{$api}/onboard/latest.jpg" target="_blank" rel="noreferrer">Open JPEG</a>
                    </div>
                    <dl class="detail-grid onboard-summary-grid">
                        <div><dt>Ready</dt><dd id="onboardReady">-</dd></div>
                        <div><dt>Enabled</dt><dd id="onboardEnabled">-</dd></div>
                        <div><dt>Frame Size</dt><dd id="onboardFrameSize">-</dd></div>
                        <div><dt>JPEG Quality</dt><dd id="onboardJpegQuality">-</dd></div>
                        <div><dt>Window</dt><dd id="onboardWindow">-</dd></div>
                        <div><dt>Clock</dt><dd id="onboardClock">-</dd></div>
                        <div><dt>Captures</dt><dd id="onboardCaptures">-</dd></div>
                        <div><dt>Failures</dt><dd id="onboardFailures">-</dd></div>
                        <div><dt>Latest JPEG</dt><dd id="onboardLatestBytes">-</dd></div>
                        <div><dt>Timelapse</dt><dd id="onboardTimelapseState">-</dd></div>
                    </dl>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <h2>Timelapse</h2>
                        <span class="quiet" id="onboardTimelapseMeta">Idle</span>
                    </div>
                    <div class="settings-grid onboard-timelapse-grid">
                        <div class="field">
                            <label for="timelapseHours">Hours</label>
                            <input id="timelapseHours" type="number" min="0.1" step="0.1" value="2">
                        </div>
                        <div class="field">
                            <label for="timelapseIntervalMs">Interval ms</label>
                            <input id="timelapseIntervalMs" type="number" min="60000" step="1000" value="300000">
                        </div>
                    </div>
                    <div class="button-row">
                        <button type="button" class="primary" id="startOnboardTimelapseBtn">Start Timelapse</button>
                        <button type="button" id="stopOnboardTimelapseBtn">Stop Timelapse</button>
                    </div>
                    <details class="diag-details">
                        <summary>Diagnostics JSON</summary>
                        <pre class="json-card" id="onboardStatusRaw">No onboard status loaded.</pre>
                    </details>
                </section>
            </div>

            <form class="onboard-settings-stack" id="onboardSettingsForm">
                <section class="panel settings-panel">
                    <div class="panel-head">
                        <h2>Schedule</h2>
                    </div>
                    <div class="settings-grid onboard-settings-grid">
                        <label class="field checkbox-field">
                            <span>Enabled</span>
                            <input id="onboardEnabledInput" name="enabled" type="checkbox">
                        </label>
                        <div class="field">
                            <label for="onboardIntervalInput">Interval ms</label>
                            <input id="onboardIntervalInput" name="interval_ms" type="number" min="5000" step="1000">
                        </div>
                        <div class="field">
                            <label for="onboardStartInput">Start</label>
                            <input id="onboardStartInput" name="start" type="time">
                        </div>
                        <div class="field">
                            <label for="onboardEndInput">End</label>
                            <input id="onboardEndInput" name="end" type="time">
                        </div>
                        <div class="field">
                            <label for="onboardTzOffsetInput">TZ Offset Min</label>
                            <input id="onboardTzOffsetInput" name="tz_offset_min" type="number" step="1">
                        </div>
                    </div>
                </section>

                <section class="panel settings-panel">
                    <div class="panel-head">
                        <h2>Image</h2>
                    </div>
                    <div class="settings-grid onboard-settings-grid">
                        <div class="field">
                            <label for="onboardFrameSizeInput">Frame Size</label>
                            <select id="onboardFrameSizeInput" name="framesize">
                                <option value="SVGA">SVGA</option>
                                <option value="XGA">XGA</option>
                                <option value="SXGA">SXGA</option>
                                <option value="UXGA">UXGA</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="onboardJpegQualityInput">JPEG Quality</label>
                            <input id="onboardJpegQualityInput" name="jpeg_quality" type="number" min="4" max="63" step="1">
                        </div>
                        <div class="field">
                            <label for="onboardBrightnessInput">Brightness</label>
                            <input id="onboardBrightnessInput" name="brightness" type="number" min="-2" max="2" step="1">
                        </div>
                        <div class="field">
                            <label for="onboardContrastInput">Contrast</label>
                            <input id="onboardContrastInput" name="contrast" type="number" min="-2" max="2" step="1">
                        </div>
                        <div class="field">
                            <label for="onboardSaturationInput">Saturation</label>
                            <input id="onboardSaturationInput" name="saturation" type="number" min="-2" max="2" step="1">
                        </div>
                        <div class="field">
                            <label for="onboardSharpnessInput">Sharpness</label>
                            <input id="onboardSharpnessInput" name="sharpness" type="number" min="-2" max="2" step="1">
                        </div>
                        <label class="field checkbox-field">
                            <span>V Flip</span>
                            <input id="onboardVflipInput" name="vflip" type="checkbox">
                        </label>
                        <label class="field checkbox-field">
                            <span>H Mirror</span>
                            <input id="onboardHmirrorInput" name="hmirror" type="checkbox">
                        </label>
                        <div class="field">
                            <label for="onboardEffectInput">Special Effect</label>
                            <input id="onboardEffectInput" name="special_effect" type="number" min="0" max="6" step="1">
                        </div>
                    </div>
                </section>

                <section class="panel settings-panel">
                    <div class="panel-head">
                        <h2>Exposure And White Balance</h2>
                    </div>
                    <div class="settings-grid onboard-settings-grid">
                        <label class="field checkbox-field">
                            <span>AWB</span>
                            <input id="onboardAwbInput" name="awb" type="checkbox">
                        </label>
                        <label class="field checkbox-field">
                            <span>AWB Gain</span>
                            <input id="onboardAwbGainInput" name="awb_gain" type="checkbox">
                        </label>
                        <div class="field">
                            <label for="onboardWbModeInput">WB Mode</label>
                            <input id="onboardWbModeInput" name="wb_mode" type="number" min="0" max="4" step="1">
                        </div>
                        <label class="field checkbox-field">
                            <span>AEC</span>
                            <input id="onboardAecInput" name="aec" type="checkbox">
                        </label>
                        <label class="field checkbox-field">
                            <span>AEC2</span>
                            <input id="onboardAec2Input" name="aec2" type="checkbox">
                        </label>
                        <div class="field">
                            <label for="onboardAeLevelInput">AE Level</label>
                            <input id="onboardAeLevelInput" name="ae_level" type="number" min="-2" max="2" step="1">
                        </div>
                        <div class="field">
                            <label for="onboardAecValueInput">AEC Value</label>
                            <input id="onboardAecValueInput" name="aec_value" type="number" min="0" max="4095" step="1">
                        </div>
                        <label class="field checkbox-field">
                            <span>AGC</span>
                            <input id="onboardAgcInput" name="agc" type="checkbox">
                        </label>
                        <div class="field">
                            <label for="onboardAgcGainInput">AGC Gain</label>
                            <input id="onboardAgcGainInput" name="agc_gain" type="number" min="0" max="30" step="1">
                        </div>
                    </div>
                </section>
            </form>
            <div class="button-row compact onboard-settings-actions">
                <button type="button" class="primary" id="saveOnboardSettingsBtn">Save Onboard Settings</button>
            </div>
        </section>

        <section class="wide-section onboard-only">
            <div class="section-head">
                <h2>Stored Photos</h2>
                <div class="button-row compact">
                    <button type="button" id="refreshOnboardMediaBtn">Refresh</button>
                    <button type="button" id="downloadAndDeleteOnboardMediaBtn">Download &amp; Delete</button>
                    <button type="button" id="deleteSelectedOnboardMediaBtn" disabled>Delete Selected</button>
                    <button type="button" id="deleteAllOnboardMediaBtn">Delete All</button>
                </div>
            </div>
            <div class="media-wrap onboard-media-wrap">
                <table class="media-table onboard-media-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAllOnboardMedia" title="Select all"></th>
                            <th>Preview</th>
                            <th>ID</th>
                            <th>Recorded</th>
                            <th>Size</th>
                            <th>Kind</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="onboardMediaRows">
                        <tr><td colspan="7" class="empty">No onboard media loaded.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="wide-section onboard-only">
            <div class="section-head">
                <h2>Server Copies</h2>
                <div class="button-row compact">
                    <button type="button" id="refreshOnboardServerMediaBtn">Refresh</button>
                    <button type="button" id="deleteSelectedOnboardServerMediaBtn" disabled>Delete Selected</button>
                </div>
            </div>
            <div class="media-wrap onboard-media-wrap">
                <table class="media-table onboard-media-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAllOnboardServerMedia" title="Select all"></th>
                            <th>Preview</th>
                            <th>ID</th>
                            <th>Recorded</th>
                            <th>Size</th>
                            <th>Kind</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="onboardServerMediaRows">
                        <tr><td colspan="7" class="empty">No onboard photos stored on this server.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="wide-section trail-only">
            <div class="section-head">
                <h2>Settings</h2>
                <div class="button-row compact">
                    <button id="loadSettingsBtn">Load</button>
                    <button class="primary" id="saveSettingsBtn">Save Changes</button>
                </div>
            </div>
            <form class="settings-grid" id="settingsForm"></form>
        </section>

        <section class="wide-section trail-only legacy-media">
            <div class="section-head">
                <h2>Media</h2>
                <div class="button-row compact">
                    <button id="refreshMediaBtn">Refresh Server</button>
                    <button id="downloadAllMediaBtn">Download All</button>
                    <button id="downloadAndDeleteMediaBtn">Download &amp; Delete</button>
                    <button id="clearServerMediaBtn">Clear Server</button>
                    <button id="deleteSelectedMediaBtn" disabled>Delete Selected</button>
                    <button id="deleteAllMediaBtn">Delete Camera</button>
                </div>
            </div>
            <div class="media-wrap">
                <table class="media-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAllMedia" title="Select all"></th>
                            <th>Preview</th>
                            <th>Type</th>
                            <th>Time</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="mediaRows"></tbody>
                </table>
            </div>
        </section>

        <section class="wide-section log-section">
            <div class="section-head">
                <h2>Activity</h2>
                <button class="text-button" id="clearLogBtn">Clear</button>
            </div>
            <pre id="logOutput"></pre>
        </section>
    </main>
    <div id="mediaLightbox" class="lightbox" hidden>
        <div class="lightbox-backdrop"></div>
        <div class="lightbox-content">
            <button class="lightbox-close" id="lightboxClose" aria-label="Close">&times;</button>
            <div id="lightboxMedia"></div>
        </div>
    </div>
    <script src="{$base}/app.js?v={$jsVer}"></script>
</body>
</html>
HTML;
    exit;
}

if (PHP_SAPI === 'cli' && isset($argv[1], $argv[2])) {
    if ($argv[1] === 'media-download-all-job') {
        run_media_download_all_job((string)$argv[2]);
        exit(0);
    }
    if ($argv[1] === 'media-delete-all-job') {
        run_media_delete_all_job((string)$argv[2]);
        exit(0);
    }
    if ($argv[1] === 'media-download-and-delete-job') {
        run_media_download_and_delete_job((string)$argv[2]);
        exit(0);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'trail-media-pull') {
    $result = pull_staged_trail_media(function (array $progress): void {
        fwrite(STDERR, json_encode($progress) . "\n");
    });
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function scheduled_camera_targets(): array
{
    $targetResponse = bridge_json('GET', '/camera/target');
    // Current bridge firmware wraps the profile list in {ok, target}; retain
    // compatibility with older firmware that returned the target directly.
    $targetData = is_array($targetResponse['target'] ?? null) ? $targetResponse['target'] : $targetResponse;
    $profiles = array_values(array_filter((array)($targetData['profiles'] ?? []), fn($profile) => is_array($profile) && !empty($profile['id'])));
    if (!$profiles) {
        throw new ApiError('camera_action_failed', 'The desktop bridge has no configured camera profiles.', 502, $targetData);
    }
    $active = is_array($targetData['active'] ?? null) ? $targetData['active'] : [];
    usort($profiles, static function (array $a, array $b) use ($active): int {
        $aActive = !empty($active['ble_mac']) && strtolower((string)($a['ble_mac'] ?? '')) === strtolower((string)$active['ble_mac']);
        $bActive = !empty($active['ble_mac']) && strtolower((string)($b['ble_mac'] ?? '')) === strtolower((string)$active['ble_mac']);
        return (int)$bActive <=> (int)$aActive;
    });
    return $profiles;
}

function select_scheduled_camera_target(array $profile): void
{
    $id = (string)($profile['id'] ?? '');
    if ($id === '') {
        throw new ApiError('invalid_request', 'Camera profile is missing its id.', 400, $profile);
    }
    // A camera switch is rejected while the bridge owns the radio for a
    // durable job. That is temporary, not a failure of this camera: wait for
    // the active job to release the radio, then retry the switch.
    $deadline = time() + max(60, (int)(getenv('TRAILCAM_SCHEDULED_SWITCH_WAIT_TIMEOUT') ?: 1800));
    do {
        $response = bridge_raw_json('POST', '/camera/target', ['id' => $id]);
        $result = $response['body'];
        if ($response['status'] !== 409) {
            if ($response['status'] >= 400 || ($result['ok'] ?? true) === false) {
                throw new ApiError('camera_action_failed', 'Desktop bridge rejected the camera-target switch.', 502, $result);
            }
            // Let the bridge finish applying its target before it starts the job.
            sleep(2);
            return;
        }
        fwrite(STDERR, json_encode([
            'event' => 'camera_switch_busy',
            'camera' => $id,
            'retry_after_seconds' => 15,
            'bridge' => $result,
        ], JSON_UNESCAPED_SLASHES) . "\n");
        sleep(15);
    } while (time() < $deadline);

    throw new ApiError('camera_timeout', 'Timed out waiting for the bridge to release the previous camera job.', 504, [
        'camera' => $id,
        'wait_timeout_seconds' => max(60, (int)(getenv('TRAILCAM_SCHEDULED_SWITCH_WAIT_TIMEOUT') ?: 1800)),
    ]);
}

// A scheduled run must not let one unreachable camera consume the entire
// day. These defaults can be raised for unusually large camera backlogs.
function scheduled_job_timeout_seconds(): int
{
    return max(300, (int)(getenv('TRAILCAM_SCHEDULED_JOB_TIMEOUT') ?: 1800));
}

function scheduled_job_stall_seconds(): int
{
    return max(60, (int)(getenv('TRAILCAM_SCHEDULED_JOB_STALL_TIMEOUT') ?: 300));
}

// Retry count and elapsed time deliberately are not included: a job that is
// only retrying camera bring-up has made no useful media-transfer progress.
function scheduled_job_progress_marker(array $job): string
{
    return implode(':', [
        (string)($job['processed'] ?? 0),
        (string)($job['downloaded'] ?? 0),
        (string)($job['skipped'] ?? 0),
        (string)($job['deleted'] ?? 0),
        (string)($job['bytes_downloaded'] ?? 0),
        (string)($job['current_media_id'] ?? 0),
    ]);
}

// The bridge treats this as a request to stop its active durable job. If an
// older bridge rejects it, that response is logged with the skipped camera.
function cancel_scheduled_durable_media_job(string $jobId): array
{
    try {
        return bridge_raw_json('POST', '/jobs', null, [
            'action' => 'cancel',
            'job_id' => $jobId,
        ]);
    } catch (Throwable $error) {
        return ['status' => 0, 'body' => ['error' => $error->getMessage()]];
    }
}

function run_scheduled_download_and_delete_for_target(array $profile): array
{
    select_scheduled_camera_target($profile);
    $cameraId = (string)$profile['id'];
    fwrite(STDOUT, json_encode(['event' => 'camera_selected', 'camera' => $cameraId, 'profile' => $profile], JSON_UNESCAPED_SLASHES) . "\n");
    $job = start_durable_media_job('download_and_delete_media');
    $jobId = (string)($job['job_id'] ?? '');
    if ($jobId === '') {
        throw new ApiError('camera_action_failed', 'Desktop bridge accepted no durable job id.', 502, $job);
    }
    fwrite(STDOUT, json_encode(['event' => 'started', 'camera' => $cameraId, 'job' => $job], JSON_UNESCAPED_SLASHES) . "\n");
    $deadline = time() + scheduled_job_timeout_seconds();
    $lastProgressMarker = null;
    $lastProgressAt = time();
    while (time() < $deadline) {
        try {
            $status = fetch_trail_media_status();
            $liveJob = is_array($status['job'] ?? null) ? $status['job'] : null;
            if (!$liveJob || (string)($liveJob['job_id'] ?? '') !== $jobId) {
                throw new ApiError('camera_action_failed', 'ESP32 no longer reports the scheduled media job.', 502, $status);
            }
            $state = strtolower((string)($liveJob['status'] ?? ''));
            fwrite(STDOUT, json_encode(['event' => 'status', 'camera' => $cameraId, 'job' => $liveJob], JSON_UNESCAPED_SLASHES) . "\n");
            if (in_array($state, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                throw new ApiError('camera_action_failed', 'Scheduled media job failed.', 502, $liveJob);
            }
            $progressMarker = scheduled_job_progress_marker($liveJob);
            if ($progressMarker !== $lastProgressMarker) {
                $lastProgressMarker = $progressMarker;
                $lastProgressAt = time();
            } elseif (time() - $lastProgressAt >= scheduled_job_stall_seconds()) {
                $cancel = cancel_scheduled_durable_media_job($jobId);
                fwrite(STDERR, json_encode([
                    'event' => 'camera_stalled',
                    'camera' => $cameraId,
                    'job_id' => $jobId,
                    'stall_seconds' => time() - $lastProgressAt,
                    'cancel' => $cancel,
                ], JSON_UNESCAPED_SLASHES) . "\n");
                throw new ApiError('camera_stalled', 'Scheduled media job made no transfer progress; skipped this camera.', 502, [
                    'camera' => $cameraId,
                    'job' => $liveJob,
                    'cancel' => $cancel,
                ]);
            }
            if (in_array($state, ['completed', 'complete', 'done', 'success', 'succeeded'], true)) {
                $pulled = pull_staged_trail_media(null, $cameraId);
                fwrite(STDOUT, json_encode(['event' => 'pull', 'camera' => $cameraId, 'result' => $pulled], JSON_UNESCAPED_SLASHES) . "\n");
                if (empty($pulled['failed'])) {
                    return $pulled;
                }
            }
        } catch (ApiError $error) {
            if ($error->errorCode !== 'bridge_unreachable') {
                throw $error;
            }
            fwrite(STDERR, json_encode(['event' => 'bridge_unavailable', 'camera' => $cameraId, 'error' => $error->getMessage()]) . "\n");
        }
        sleep(15);
    }
    throw new ApiError('camera_timeout', 'Scheduled Download and Delete timed out before staged files were copied.', 504, ['camera' => $cameraId]);
}

// Used by the local morning scheduler. The ESP32 accepts only one durable
// media job at a time; an already-running board job is adopted rather than
// duplicated by start_durable_media_job(). The board stages files on its SD,
// so this worker remains alive until it can also pull those files to the
// server; no browser session is required.
if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'durable-download-and-delete') {
    try {
        $results = [];
        $errors = [];
        foreach (scheduled_camera_targets() as $profile) {
            $cameraId = (string)$profile['id'];
            try {
                $results[$cameraId] = run_scheduled_download_and_delete_for_target($profile);
            } catch (Throwable $error) {
                $errors[$cameraId] = [
                    'error' => $error->getMessage(),
                    'details' => $error instanceof ApiError ? $error->details : null,
                ];
                fwrite(STDERR, json_encode(['event' => 'camera_failed', 'camera' => $cameraId] + $errors[$cameraId], JSON_UNESCAPED_SLASHES) . "\n");
            }
        }
        $stateFile = read_durable_media_job_state();
        if ($stateFile) {
            $stateFile['server_pull_completed_at'] = gmdate('c');
            $stateFile['scheduled_cameras'] = array_keys($results);
            $stateFile['scheduled_camera_errors'] = $errors;
            write_durable_media_job_state($stateFile);
        }
        fwrite(STDOUT, json_encode(['event' => 'completed', 'cameras' => array_keys($results), 'results' => $results, 'errors' => $errors], JSON_UNESCAPED_SLASHES) . "\n");
        exit($errors ? 1 : 0);
    } catch (Throwable $error) {
        fwrite(STDERR, json_encode([
            'error' => $error->getMessage(),
            'details' => $error instanceof ApiError ? $error->details : null,
        ], JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = route_path();

    if ($path === '/') {
        render_ui('trail');
    }
    if ($path === '/trail') {
        render_ui('trail');
    }
    if ($path === '/media') {
        render_ai_media();
    }
    if ($path === '/onboard') {
        render_ui('onboard');
    }
    if ($method === 'GET' && preg_match('#^/api/ai-media/(\d+)/(file|thumb)$#', $path, $m)) {
        proxy_ai_media((int)$m[1], $m[2] === 'thumb');
    }
    if ($method === 'POST' && $path === '/api/ai-media/delete-selected') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'delete-selected') throw new ApiError('unsafe_action_blocked', 'Confirmation is required.', 403);
        $ids = array_values(array_filter((array)($body['ids'] ?? []), fn($id) => is_numeric($id) && (int)$id > 0));
        if (!$ids) throw new ApiError('invalid_request', 'No media items selected.', 400);
        json_response(delete_ai_media($ids));
    }
    if ($method === 'GET' && $path === '/api/media-protection') {
        json_response(['ids' => array_keys(media_protection_ids())]);
    }
    if ($method === 'POST' && $path === '/api/media-protection') {
        $body = request_json(); $id = (int)($body['id'] ?? 0);
        if ($id <= 0) throw new ApiError('invalid_request', 'A media id is required.', 400);
        set_media_protection($id, !empty($body['keep'])); json_response(['ok' => true]);
    }
    if ($method === 'GET' && $path === '/live/gardepro.sdp') {
        $sdpPath = live_sdp_path();
        if (!is_file($sdpPath)) {
            throw new ApiError('not_found', 'Live SDP file is not available.', 404);
        }
        header('Content-Type: application/sdp');
        readfile($sdpPath);
        exit;
    }
    if ($method === 'GET' && $path === '/api/live/snapshot') {
        $snapshotPath = ensure_live_snapshot();
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        readfile($snapshotPath);
        exit;
    }
    if ($method === 'GET' && $path === '/api/live/mjpeg') {
        proxy_live_mjpeg();
    }
    if ($method === 'GET' && $path === '/api/onboard/latest.jpg') {
        proxy_onboard_latest_jpg();
    }
    if ($method === 'GET' && $path === '/api/onboard/media') {
        json_response(onboard_media_list($_GET));
    }
    if ($method === 'GET' && $path === '/api/onboard/server-media') {
        json_response(['media' => onboard_server_media_items()]);
    }
    if ($method === 'GET' && preg_match('#^/api/onboard/server-media/(.+)$#', $path, $m)) {
        proxy_onboard_server_media_file(rawurldecode($m[1]));
    }
    if ($method === 'DELETE' && preg_match('#^/api/onboard/server-media/(.+)$#', $path, $m)) {
        json_response(delete_onboard_server_media_file(rawurldecode($m[1])));
    }
    if ($method === 'POST' && $path === '/api/onboard/server-media/delete-selected') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'delete-selected') {
            throw new ApiError('unsafe_action_blocked', 'Server-media bulk delete requires confirm=delete-selected.', 403);
        }
        $filenames = array_values(array_filter((array)($body['filenames'] ?? []), 'is_string'));
        if (!$filenames) {
            throw new ApiError('invalid_request', 'No server media files were selected.', 400);
        }
        json_response(delete_selected_onboard_server_media($filenames));
    }
    if ($method === 'POST' && $path === '/api/onboard/media/delete_all') {
        json_response(onboard_media_delete_all());
    }
    if ($method === 'POST' && $path === '/api/onboard/media/download-and-delete') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'download-and-delete') {
            throw new ApiError('unsafe_action_blocked', 'Onboard download and delete requires confirm=download-and-delete.', 403);
        }
        json_response(download_and_delete_onboard_media());
    }
    if ($method === 'POST' && $path === '/api/onboard/media/delete-selected') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'delete-selected') {
            throw new ApiError('unsafe_action_blocked', 'Delete selected requires confirm=delete-selected.', 403);
        }
        $ids = array_filter((array)($body['ids'] ?? []), fn($v) => is_string($v) && preg_match('/^[A-Za-z0-9_-]+$/', $v));
        if (!$ids) throw new ApiError('invalid_request', 'No ids provided.', 400);
        $deleted = []; $errors = [];
        foreach ($ids as $id) {
            try {
                onboard_media_delete((string)$id);
                $deleted[] = $id;
            } catch (Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        json_response(['deleted' => $deleted, 'errors' => $errors]);
    }
    if ($method === 'GET' && preg_match('#^/api/onboard/media/([A-Za-z0-9_-]+)(/thumb)?$#', $path, $m)) {
        proxy_onboard_media_file($m[1], !empty($m[2]));
    }
    if ($method === 'DELETE' && preg_match('#^/api/onboard/media/([A-Za-z0-9_-]+)$#', $path, $m)) {
        json_response(onboard_media_delete($m[1]));
    }
    if ($method === 'GET' && $path === '/api/status') {
        maybe_close_expired_camera_session();
        $raw = cached_bridge_status();
        try {
            $liveView = bridge_control_summary();
            $raw['live_view_active'] = !empty($liveView['live_view_active']);
            if (array_key_exists('tunnel_connected', $liveView)) {
                $raw['tunnel_connected'] = !empty($liveView['tunnel_connected']);
            }
        } catch (Throwable) {
        }
        json_response(normalize_status($raw));
    }
    if ($method === 'GET' && $path === '/api/control/summary') {
        json_response(bridge_control_summary());
    }
    if ($method === 'GET' && $path === '/api/onboard/status') {
        json_response(onboard_status());
    }
    if ($method === 'POST' && $path === '/api/onboard/capture') {
        json_response(onboard_capture(request_json()));
    }
    if ($method === 'POST' && $path === '/api/onboard/config') {
        json_response(onboard_config(request_json()));
    }
    if ($method === 'POST' && $path === '/api/onboard/timelapse/start') {
        json_response(onboard_timelapse_start(request_json()));
    }
    if ($method === 'POST' && $path === '/api/onboard/timelapse/stop') {
        json_response(onboard_timelapse_stop());
    }
    if ($method === 'GET' && $path === '/api/camera/target') {
        json_response(bridge_json('GET', '/camera/target'));
    }
    if ($method === 'POST' && $path === '/api/camera/target') {
        $body = request_json();
        $query = !empty($body['id']) ? ['id' => $body['id']] : null;
        $payload = !empty($body['ble_mac']) ? array_filter($body, fn($k) => in_array($k, ['ble_mac', 'wifi_ssid', 'ble_name']), ARRAY_FILTER_USE_KEY) : null;
        if ($query === null && $payload === null) {
            throw new ApiError('invalid_request', 'Either id or ble_mac is required.');
        }
        $result = bridge_json('POST', '/camera/target', $query, $payload ?: null);
        invalidate_api_cache('bridge.status');
        json_response($result);
    }
    if ($method === 'POST' && $path === '/api/session/open') {
        json_response(with_camera_lock(fn() => open_session(request_json())));
    }
    if ($method === 'POST' && $path === '/api/session/close') {
        json_response(with_camera_lock(fn() => close_session(request_json())));
    }
    if ($method === 'GET' && $path === '/api/settings/schema') {
        json_response(settings_schema());
    }
    if ($method === 'GET' && $path === '/api/settings') {
        $settings = remember_api_cache('settings', 'camera.settings', fn() => with_camera_session(fn() => camera_json('GET', '/cmd/getSetting')));
        json_response(['settings' => is_array($settings) ? ($settings['data'] ?? $settings) : $settings]);
    }
    if ($method === 'POST' && $path === '/api/settings') {
        $body = request_json();
        $patch = $body['patch'] ?? null;
        if (!is_array($patch)) {
            throw new ApiError('invalid_request', 'patch object is required.');
        }
        $unknown = array_values(array_diff(array_keys($patch), safe_setting_keys()));
        if ($unknown) {
            throw new ApiError('unsafe_action_blocked', 'Patch contains unsafe setting keys.', 403, $unknown);
        }
        $result = with_camera_session(fn() => camera_json('POST', '/cmd/setSetting', ['data' => $patch]));
        invalidate_api_cache('camera.settings');
        json_response(['changed' => $patch, 'unchanged' => new stdClass(), 'after' => $result]);
    }
    if ($method === 'GET' && $path === '/api/media/active-job') {
        $labels = [
            'download_all_media' => 'Download all',
            'download_and_delete_media' => 'Download and delete',
            'delete_all_media' => 'Delete camera',
        ];
        $found = null;
        foreach ($labels as $type => $label) {
            $job = active_media_job($type);
            if ($job) {
                $found = array_merge($job, ['label' => $label]);
                break;
            }
        }
        json_response($found ? ['job' => $found] : ['job' => null]);
    }
    if ($method === 'GET' && $path === '/api/media') {
        json_response(['items' => list_media_items()]);
    }
    if ($method === 'GET' && $path === '/api/media/latest') {
        $type = $_GET['type'] ?? '';
        $items = list_media_items();
        $match = $type ? array_values(array_filter($items, fn($i) => $i['type'] === $type)) : $items;
        json_response(['item' => $match[0] ?? null]);
    }
    if ($method === 'POST' && $path === '/api/media/download-all') {
        accepted_json_response(start_durable_media_job('download_all_media'));
    }
    if ($method === 'GET' && preg_match('#^/api/media/jobs/([a-zA-Z0-9_-]+)$#', $path, $m)) {
        $job = read_media_job($m[1]);
        if (!$job) {
            throw new ApiError('not_found', 'Media job was not found.', 404);
        }
        json_response($job);
    }
    if ($method === 'GET' && preg_match('#^/api/media/push/(\d+)/(download|thumb)$#', $path, $m)) {
        proxy_pushed_media_file((int)$m[1], $m[2] === 'thumb');
    }
    if ($method === 'GET' && preg_match('#^/api/media/(\d+)/(download|thumb)$#', $path, $m)) {
        proxy_camera_file((int)$m[1], $m[2] === 'thumb');
    }
    if ($method === 'DELETE' && preg_match('#^/api/media/(\d+|push-\d+)$#', $path, $m)) {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'delete') {
            throw new ApiError('unsafe_action_blocked', 'Delete requires confirm=delete.', 403);
        }
        $id = $m[1];
        if (!delete_gallery_media($id)) {
            throw new ApiError('not_found', 'Server media item was not found.', 404);
        }
        invalidate_api_cache('media.gallery');
        json_response(['deleted' => true, 'server_only' => true]);
    }
    if ($method === 'POST' && $path === '/api/media/delete-selected') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'delete-selected') {
            throw new ApiError('unsafe_action_blocked', 'Delete selected requires confirm=delete-selected.', 403);
        }
        $ids = array_values(array_filter((array)($body['ids'] ?? []), fn($v) => is_string($v) && preg_match('/^(\d+|push-\d+)$/', $v)));
        if (!$ids) {
            throw new ApiError('invalid_request', 'No ids provided.', 400);
        }
        $deleted = [];
        $errors = [];
        foreach ($ids as $id) {
            if (delete_gallery_media($id)) {
                $deleted[] = $id;
            } else {
                $errors[] = ['id' => $id, 'error' => 'Server media item was not found.'];
            }
        }
        invalidate_api_cache('media.gallery');
        json_response(['deleted' => $deleted, 'errors' => $errors]);
    }
    if ($method === 'POST' && $path === '/api/media/delete-all') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'delete-all') {
            throw new ApiError('unsafe_action_blocked', 'Delete all requires confirm=delete-all.', 403);
        }
        $active = active_media_job('delete_all_media');
        if ($active) {
            json_response($active);
        }
        $job = create_media_job('delete_all_media');
        start_media_job_process($job['job_id'], 'media-delete-all-job');
        accepted_json_response($job);
    }
    if ($method === 'POST' && $path === '/api/media/clear-server') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'clear-server') {
            throw new ApiError('unsafe_action_blocked', 'Clear server requires confirm=clear-server.', 403);
        }
        json_response(clear_server_media());
    }
    if ($method === 'POST' && $path === '/api/media/download-and-delete') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'download-and-delete') {
            throw new ApiError('unsafe_action_blocked', 'Download and delete requires confirm=download-and-delete.', 403);
        }
        accepted_json_response(start_durable_media_job('download_and_delete_media'));
    }
    if ($method === 'POST' && $path === '/api/media/durable/start') {
        $body = request_json();
        $action = (string)($body['action'] ?? 'download_all_media');
        if ($action === 'download_and_delete_media' && ($body['confirm'] ?? '') !== 'download-and-delete-media') {
            throw new ApiError('unsafe_action_blocked', 'Durable download-and-delete requires confirm=download-and-delete-media.', 403);
        }
        accepted_json_response(start_durable_media_job($action));
    }
    if ($method === 'GET' && $path === '/api/media/durable/status') {
        json_response(reconcile_durable_media_job());
    }
    if ($method === 'POST' && $path === '/api/media/durable/pull') {
        json_response(pull_completed_durable_trail_media());
    }
    if ($method === 'POST' && $path === '/api/actions/take-picture') {
        @set_time_limit(180);
        $result = with_camera_session(fn() => take_picture_via_bridge());
        invalidate_api_cache('bridge.status');
        invalidate_api_cache('media.gallery');
        json_response($result);
    }
    if ($method === 'POST' && $path === '/api/actions/video/start') {
        json_response(with_camera_lock(function () {
            open_session([]);
            $result = camera_json('GET', '/media/video/start');
            touch_camera_session_lease();
            invalidate_api_cache('bridge.status');
            return $result;
        }));
    }
    if ($method === 'POST' && $path === '/api/actions/video/stop') {
        json_response(with_camera_lock(function () {
            $result = camera_json('GET', '/media/video/stop');
            close_session(['standby' => true]);
            invalidate_api_cache('bridge.status');
            invalidate_api_cache('media.gallery');
            return $result;
        }));
    }
    if ($method === 'POST' && $path === '/api/live/start') {
        json_response(with_camera_lock(function () {
            open_session([]);
            $timeout = (int)(getenv('GARDEPRO_LIVE_START_TIMEOUT') ?: 180);
            $interval = (float)(getenv('GARDEPRO_LIVE_START_POLL_INTERVAL') ?: 1);
            // Firmware 0.2.7 connects GPRT after RTSP SETUP and before PLAY.
            // Verify the receiver before submitting the durable start job.
            if (!live_tunnel_listener_ready()) {
                throw new ApiError('live_receiver_unavailable', 'The local GPRT tunnel listener on port 6000 is not ready.', 503);
            }
            $existing = bridge_control_summary();
            if (!empty($existing['control_busy'])) {
                $existing = wait_for(
                    fn() => bridge_control_summary(),
                    fn($s) => is_array($s) && empty($s['control_busy']),
                    $timeout,
                    $interval,
                    'control_error_abort'
                );
            }
            if (!empty($existing['live_view_active'])) {
                $existingHealth = live_stream_health($existing);
                if (!empty($existingHealth['restart_required'])) {
                    stop_live_mjpeg_relay();
                    $stopResult = submit_live_view_job('live_view_stop');
                    $stopTimeout = (int)($stopResult['eta_sec'] ?? $timeout);
                    $stopPollPath = live_job_poll_path($stopResult);
                    wait_for(
                        fn() => bridge_json('GET', $stopPollPath),
                        fn($s) => is_array($s) && empty($s['live_view_active']) && empty($s['tunnel_connected']) && empty($s['control_busy']) && !empty($s['control_last_ok']),
                        $stopTimeout,
                        $interval,
                        'control_error_abort'
                    );
                    $existing = bridge_control_summary();
                }
            }
            $startResult = [];
            if (empty($existing['live_view_active'])) {
                $startResult = submit_live_view_job('live_view_start');
                // A short job ETA must not shorten the HTTP allowance that
                // protects slow camera wake/RTSP setup.
                $timeout = max($timeout, (int)($startResult['eta_sec'] ?? 0));
            }
            $status = !empty($existing['live_view_active'])
                ? $existing
                : wait_for_live_stream(live_job_poll_path($startResult), $timeout, $interval);
            $health = live_stream_health($status);
            $relayReady = !empty($health['stream_active']) && start_live_mjpeg_relay();
            touch_camera_session_lease(300);
            invalidate_api_cache('bridge.status');
            return [
                'stream_active' => !empty($status['live_view_active']),
                'stream_start_requested' => true,
                'stream_start_completed' => !empty($status['live_view_active']),
                'player_url' => public_api_base() . '/live/mjpeg',
                'player_ready' => $relayReady,
                'mjpeg_relay_active' => $relayReady,
                'status' => $status,
                'live_health' => $health,
                'timeout_sec' => $timeout,
            ];
        }));
    }
    if ($method === 'POST' && $path === '/api/live/stop') {
        json_response(with_camera_lock(function () {
            $result = close_session(['standby' => true]);
            invalidate_api_cache('bridge.status');
            return $result;
        }));
    }
    if ($method === 'GET' && $path === '/api/live/status') {
        maybe_close_expired_camera_session();
        try {
            $status = bridge_control_summary();
        } catch (Throwable) {
            $status = [];
        }
        $health = live_stream_health($status);
        if (!empty($health['restart_required'])) {
            stop_live_mjpeg_relay();
        }
        json_response([
            'stream_active' => !empty($health['stream_active']),
            'tunnel_connected' => !empty($health['tunnel_connected']),
            'player_ready' => live_mjpeg_process_running(),
            'mjpeg_relay_active' => live_mjpeg_process_running(),
            'player_url' => public_api_base() . '/live/mjpeg',
            'status' => $status,
            'live_health' => $health,
        ]);
    }
    // ESP32 bridge management routes introduced by firmware 0.2.9.  A full
    // restart intentionally does not use the camera-session lock: the board
    // acknowledges first, then drops the HTTP connection while rebooting.
    if ($method === 'GET' && $path === '/api/system/status') {
        json_response(bridge_json('GET', '/system/status'));
    }
    if ($method === 'GET' && $path === '/api/sd/status') {
        json_response(bridge_json('GET', '/sd/status'));
    }
    if ($method === 'POST' && $path === '/api/sd/mount') {
        $result = bridge_raw_json('POST', '/sd/mount', null, []);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new ApiError('camera_action_failed', 'ESP32 could not mount its SD card.', 502, $result['body']);
        }
        invalidate_api_cache('bridge.status');
        json_response($result['body']);
    }
    if ($method === 'POST' && $path === '/api/system/http-restart') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'restart-http') {
            throw new ApiError('unsafe_action_blocked', 'HTTP listener restart requires confirm=restart-http.', 403);
        }
        $result = bridge_raw_json('POST', '/system/http_restart', null, []);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new ApiError('camera_action_failed', 'ESP32 rejected the HTTP listener restart.', 502, $result['body']);
        }
        invalidate_api_cache('bridge.status');
        accepted_json_response($result['body']);
    }
    if ($method === 'POST' && $path === '/api/system/restart') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'restart-esp32') {
            throw new ApiError('unsafe_action_blocked', 'ESP32 restart requires confirm=restart-esp32.', 403);
        }
        $result = bridge_raw_json('POST', '/system/restart', null, []);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new ApiError('camera_action_failed', 'ESP32 rejected the restart request.', 502, $result['body']);
        }
        clear_camera_session_state();
        invalidate_api_cache('bridge.status');
        accepted_json_response($result['body']);
    }
    if ($method === 'GET' && $path === '/api/admin/clock') {
        $clock = remember_api_cache('clock', 'camera.clock', fn() => with_camera_session(fn() => camera_json('GET', '/cmd/info/4')));
        json_response(['clock' => $clock]);
    }
    if ($method === 'POST' && $path === '/api/admin/set-clock') {
        $body = request_json();
        if (empty($body['timestamp_utc'])) {
            throw new ApiError('invalid_request', 'timestamp_utc is required.');
        }
        $result = with_camera_session(fn() => camera_json('POST', '/cmd/setGmtClock', ['data' => $body['timestamp_utc']]));
        invalidate_api_cache('bridge.status');
        invalidate_api_cache('camera.clock');
        json_response($result);
    }
    if ($method === 'POST' && $path === '/api/admin/cache/clear') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'clear-cache') {
            throw new ApiError('unsafe_action_blocked', 'Cache clear requires confirm=clear-cache.', 403);
        }
        clear_api_cache();
        clear_camera_session_state();
        json_response(['cleared' => true]);
    }
    if ($method === 'GET' && $path === '/api/admin/format-status') {
        json_response(with_camera_session(fn() => camera_json('GET', '/cmd/format/result')));
    }
    if ($method === 'POST' && $path === '/api/admin/format-sd') {
        $body = request_json();
        if (($body['confirm'] ?? '') !== 'FORMAT SD') {
            throw new ApiError('unsafe_action_blocked', 'Format requires confirm="FORMAT SD".', 403);
        }
        $result = with_camera_session(fn() => camera_json('GET', '/cmd/format/start'));
        invalidate_api_cache('bridge.status');
        invalidate_api_cache('media.gallery');
        json_response($result);
    }
    if ($method === 'POST' && $path === '/api/observations/upload') {
        handle_observations_upload(request_json());
    }
    if ($method === 'POST' && $path === '/api/board/telemetry') {
        handle_board_telemetry(request_json());
    }
    if ($method === 'GET' && $path === '/api/board/telemetry/latest') {
        json_response(['telemetry' => latest_board_telemetry($_GET['scanner_host'] ?? null)]);
    }
    if ($method === 'GET' && $path === '/api/board/status/latest') {
        json_response(['status' => latest_board_status_snapshot($_GET['scanner_host'] ?? null)]);
    }
    if ($method === 'POST' && $path === '/api/board/camera-connections') {
        handle_camera_connections(request_json());
    }
    if ($method === 'GET' && $path === '/api/board/camera-connections') {
        json_response(['connections' => list_camera_connections($_GET)]);
    }
    if ($method === 'POST' && $path === '/api/board/media') {
        handle_board_media(request_json());
    }
    if ($method === 'PUT' && preg_match('#^/api/board/media/(\d+)/blob$#', $path, $m)) {
        handle_media_blob((int)$m[1]);
    }
    if ($method === 'POST' && $path === '/api/board/events') {
        handle_board_events(request_json());
    }

    throw new ApiError('not_found', 'Route not found.', 404);
} catch (ApiError $e) {
    error_response($e);
} catch (Throwable $e) {
    error_response(new ApiError('internal_error', 'Internal server error.', 500, $e->getMessage()));
}
