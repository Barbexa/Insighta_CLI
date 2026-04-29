<?php
// 1. CONFIGURATION
const API_BASE_URL = "https://profile-intelligence-api.pxxl.click/api/v1/";
$home = getenv('USERPROFILE') ?: getenv('HOME');
$configDir = $home . DIRECTORY_SEPARATOR . '.insighta';
$credFile = $configDir . DIRECTORY_SEPARATOR . 'credentials.json';

if (!is_dir($configDir))
    mkdir($configDir, 0700, true);

// 2. HELPERS
function parseFlags($args)
{
    $params = [];
    for ($i = 0; $i < count($args); $i++) {
        if (strpos($args[$i], '--') === 0) {
            $key = str_replace('--', '', $args[$i]);
            $key = str_replace('-', '_', $key);
            $params[$key] = $args[$i + 1] ?? true;
            $i++;
        }
    }
    return $params;
}

function api_request($endpoint, $method = 'GET', $data = null)
{
    global $credFile;
    $creds = file_exists($credFile) ? json_decode(file_get_contents($credFile), true) : null;
    $ch = curl_init(API_BASE_URL . $endpoint);
    $headers = ["X-API-Version: 1", "Accept: application/json"];
    if ($creds)
        $headers[] = "Authorization: Bearer " . $creds['access'];
    if ($data)
        $headers[] = "Content-Type: application/json";

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($data)
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($response, true), 'raw' => $response];
}

function renderTable($data)
{
    if (empty($data)) {
        echo "📭 No profiles found.\n";
        return;
    }
    $mask = "| %-38.38s | %-15.15s | %-10.10s | %-10.10s |\n";
    $line = "+" . str_repeat("-", 40) . "+" . str_repeat("-", 17) . "+" . str_repeat("-", 12) . "+" . str_repeat("-", 12) . "+\n";
    echo $line;
    printf($mask, "ID", "NAME", "GENDER", "COUNTRY");
    echo $line;
    foreach ($data as $row) {
        printf($mask, $row['id'], ucfirst($row['name']), $row['gender'] ?? 'N/A', $row['country_id'] ?? '??');
    }
    echo $line;
}

// 3. MAIN ROUTER
$command = $argv[1] ?? '';
$subcommand = $argv[2] ?? '';

switch ($command) {
    case 'login':
        echo "🔑 Login to Insighta\n";
        $access = readline("Access Token: ");
        $refresh = readline("Refresh Token: ");
        file_put_contents($credFile, json_encode(['access' => $access, 'refresh' => $refresh]));
        echo "✅ Authenticated! Tokens saved to: $credFile\n";
        break;

    case 'logout':
        if (file_exists($credFile))
            unlink($credFile);
        echo "👋 Logged out successfully.\n";
        break;

    case 'whoami':
        $res = api_request("auth/me");
        if ($res['status'] === 200) {
            echo "👤 Logged in as: @" . ($res['body']['data']['username'] ?? 'User') . "\n";
        } else {
            echo "❌ Not logged in or session expired.\n";
        }
        break;

    case 'profiles':
        $flags = parseFlags(array_slice($argv, 3));

        if ($subcommand === 'list') {
            echo "⏳ Fetching profiles...\n";
            $res = api_request("profiles/?" . http_build_query($flags));
            if ($res['status'] === 200)
                renderTable($res['body']['data']);
            else
                echo "❌ Error: " . ($res['body']['message'] ?? 'Fetch failed') . "\n";
        } elseif ($subcommand === 'get') {
            $id = $argv[3] ?? '';
            $res = api_request("profiles/" . $id);
            if ($res['status'] === 200)
                renderTable([$res['body']['data']]);
        } elseif ($subcommand === 'search') {
            $q = $argv[3] ?? '';
            $res = api_request("profiles/search?q=" . urlencode($q));
            if ($res['status'] === 200)
                renderTable($res['body']['data']);
        } elseif ($subcommand === 'create') {
            if (!isset($flags['name'])) {
                echo "❌ --name is required\n";
                break;
            }
            $res = api_request("profiles/", "POST", ["name" => $flags['name']]);
            if ($res['status'] === 201) {
                echo "✅ Created!\n";
                renderTable([$res['body']['data']]);
            } else {
                echo "❌ Error (" . $res['status'] . "): " . ($res['body']['message'] ?? 'Access Denied') . "\n";
            }
        } elseif ($subcommand === 'export') {
            echo "📊 Exporting CSV...\n";
            $flags['format'] = 'csv';
            $res = api_request("profiles/export?" . http_build_query($flags));
            // Inside the 'export' elseif block:
            $filename = $home . DIRECTORY_SEPARATOR . "Documents" . DIRECTORY_SEPARATOR . "profiles_" . time() . ".csv";

            // Save the file
            file_put_contents($filename, $res['raw']);

            echo "✅ Success! File saved to: " . $filename . "\n";
        }
        break;

    default:
        echo "Usage: insighta [login|logout|whoami|profiles list|profiles search|profiles create|profiles export]\n";
        break;
}