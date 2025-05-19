<?php
error_reporting(0);
ini_set('display_errors', 0);

$server="1";
$rpcuser="dogeuser";
$rpcpassword="dogepass";
$rpcallowip="127.0.0.1";
$rpcport="22555";

// --- Rate Limiter Settings ---
$maxRequests = 100;
$timeWindow = 60*10; // seconds

$clientIP = $_SERVER['REMOTE_ADDR'];
$rateFile = sys_get_temp_dir() . "/dogeapi_rate_" . md5($clientIP);

// Load or initialize data
if (file_exists($rateFile)) {
    $data = json_decode(file_get_contents($rateFile), true);
    if (!is_array($data)) $data = [];
} else {
    $data = [];
}

// Clean old timestamps
$now = time();
$data = array_filter($data, function ($timestamp) use ($now, $timeWindow) {
    return ($timestamp + $timeWindow) >= $now;
});

// Enforce limit
if (count($data) >= $maxRequests) {
    header('Content-Type: application/json', true, 429);
    echo json_encode([
        'error' => 'Rate limit exceeded',
        'limit' => $maxRequests,
        'window' => $timeWindow . ' seconds',
        'try_again_in' => $data[0] + $timeWindow - $now
    ]);
    exit;
}

// Log this request
$data[] = $now;
file_put_contents($rateFile, json_encode($data), LOCK_EX);



class DogecoinRPC {
    private $user;
    private $password;
    private $host;
    private $port;
    private $url;

    public function __construct($user, $password, $host = '127.0.0.1', $port = 22555) {
        $this->user = $user;
        $this->password = $password;
        $this->host = $host;
        $this->port = $port;
        $this->url = "http://{$host}:{$port}/";
    }

    private function rpcCall($method, $params = []) {
        $payload = json_encode([
            'jsonrpc' => '1.0',
            'id'      => 'phpclient',
            'method'  => $method,
            'params'  => $params
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->user}:{$this->password}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            return ['error' => curl_error($ch)];
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['error']) && $decoded['error'] !== null) {
            return ['error' => $decoded['error']];
        }

        return $decoded['result'];
    }

public function listTransactions($account = "*", $count = 10, $skip = 0) {
    return $this->request("listtransactions", [$account, $count, $skip]);
}

    public function getBalance($account = '*') {
        return $this->rpcCall('getbalance', [$account]);
    }

    public function listUnspent($min = 1, $max = 9999999, $addresses = []) {
        return $this->rpcCall('listunspent', [$min, $max, $addresses]);
    }

    public function sendRawTransaction($hex) {
        return $this->rpcCall('sendrawtransaction', [$hex]);
    }

    public function validateAddress($address) {
        return $this->rpcCall('validateaddress', [$address]);
    }

    public function createRawTransaction($inputs, $outputs) {
        return $this->rpcCall('createrawtransaction', [$inputs, $outputs]);
    }

    public function signRawTransactionWithWallet($hex) {
        return $this->rpcCall('signrawtransactionwithwallet', [$hex]);
    }
}
// index.php
//require_once 'DogecoinRPC.php';

// Set headers for JSON API
header('Content-Type: application/json');

// RPC connection settings (change as needed)
$rpc = new DogecoinRPC('dogeuser', 'dogepass', '127.0.0.1', 22555);
// Default welcome page for HTML format
if (empty($_GET['action']) && empty($_POST['action'])) {
        echo '<!DOCTYPE html><html><head><title>Welcome to Dogecoin RPC API</title></head><body>';
        echo '<h1>Welcome to the Dogecoin RPC API!</h1>';
        echo '<p>Use the <code>action</code> parameter to interact with the API. Example actions: <strong>getbalance</strong>, <strong>validateaddress</strong>, <strong>listunspent</strong>, <strong>sendrawtransaction</strong>.</p>';
        echo '<p>Example usage: <code>GET /index.php?action=getbalance</code></p>';
        echo '</body></html>';

    exit; // Stop further execution
}

// Parse the route from GET or POST
$action = $_GET['action'] ?? $_POST['action'] ?? null;

switch ($action) {
    case 'getbalance':
        $account = $_GET['account'] ?? '*';
        $result = $rpc->getBalance($account);
        echo json_encode(['balance' => $result]);
        break;

    case 'validateaddress':
        $address = $_GET['address'] ?? '';
        if (!$address) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing address']);
            break;
        }
        $result = $rpc->validateAddress($address);
        echo json_encode($result);
        break;

    case 'listunspent':
        $address = $_GET['address'] ?? '';
        if (!$address) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing address']);
            break;
        }
        $result = $rpc->listUnspent(1, 9999999, [$address]);
        echo json_encode($result);
        break;
    case 'listtransactions':
    	$account = $_GET['account'] ?? '*';
    	$count = isset($_GET['count']) ? intval($_GET['count']) : 10;
    	$skip = isset($_GET['skip']) ? intval($_GET['skip']) : 0;

    	// Enforce reasonable upper limit
    	if ($count > 1000) $count = 1000;

    	$result = $rpc->listTransactions($account, $count, $skip);
    	echo json_encode($result);
    	break;

    case 'sendrawtransaction':
        $hex = $_POST['hex'] ?? '';
        if (!$hex) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing raw transaction hex']);
            break;
        }
        $result = $rpc->sendRawTransaction($hex);
        echo json_encode(['txid' => $result]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown or missing action']);
}

?>
