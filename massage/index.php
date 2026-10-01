<?php

/**
 * Main Terminal Chat Interface
 * Uses db.php for authentication and session setup.
 * Renders the terminal UI and handles WebSocket-like polling via AJAX.
 */

use ROOTS\Terminal\TerminalAuth;
use ROOTS\Terminal\TerminalException;

if (!class_exists(TerminalAuth::class)) {
    require_once __DIR__ . "/../vendor/autoload.php";
}

try {
    [$mysqli, $user_id] = TerminalAuth::init();
} catch (TerminalException $e) {
    error_log("[Terminal Init Error] " . $e->getMessage());
    die("Initialization failed.");
}

// Validate that database connection and user authentication succeeded
if (!isset($mysqli) || !($mysqli instanceof \mysqli)) {
    error_log(
        "[Terminal Error] Database connection not available in index.php",
    );
    die("System error: Database unavailable. Please contact support.");
}

if (!isset($user_id) || !is_numeric($user_id) || $user_id <= 0) {
    error_log(
        "[Terminal Error] User ID not set or invalid after db.php inclusion",
    );
    session_unset();
    session_destroy();
    header("Location: ../login?error=session_invalid");
    exit();
}

// Fetch current user stats for the top bar
// We re-query here to get the fresh points/sub status
$currentUser = null;
try {
    $stmt = $mysqli->prepare(
        "SELECT username, subscription, points FROM login WHERE id = ? LIMIT 1",
    );
    if (!$stmt) {
        throw new TerminalException(
            "Failed to prepare user stats query: " . $mysqli->error,
        );
    }

    $stmt->bind_param("i", $user_id);

    if (!$stmt->execute()) {
        throw new TerminalException(
            "Failed to execute user stats query: " . $stmt->error,
        );
    }

    $result = $stmt->get_result();
    $currentUser = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$currentUser) {
        throw new TerminalException(
            "User not found in database after authentication",
        );
    }
} catch (TerminalException $e) {
    error_log(
        "[Terminal Error] Failed to fetch user stats: " . $e->getMessage(),
    );
    die("Failed to load user information. Please try logging in again.");
} catch (\Exception $e) {
    error_log("[Terminal Error] Unexpected error: " . $e->getMessage());
    die("An unexpected system error occurred.");
}

// Sanitize user data for safe HTML output
$safeUsername = htmlspecialchars((string) $currentUser["username"], ENT_QUOTES, "UTF-8");
$safeSubscription = htmlspecialchars(
    (string) $currentUser["subscription"],
    ENT_QUOTES,
    "UTF-8",
);
$safePoints = htmlspecialchars((string) $currentUser["points"], ENT_QUOTES, "UTF-8");

// Check if user is banned for navbar rendering
$isUserBanned = \ROOTS\Auth\BanSystem::isBanned($user_id);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radio ROOTS - Terminal</title>
    <link href="favicon.ico" rel="icon">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Radio ROOTS Terminal Chat Interface">
    <meta name="robots" content="noindex, nofollow">

    <style>
        /* --- General design: Deep terminal screen --- */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body,
        html {
            margin: 0;
            padding: 0;
            height: 100%;
            width: 100%;
            background-color: #000000;
            /* Pure black */
            color: #00aa00;
            /* Classic terminal green */
            font-family: 'Courier New', Courier, monospace;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* --- Top bar: Time and account information --- */
        #top-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            height: 25px;
            background-color: #001100;
            border-bottom: 1px solid #004400;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 10px;
            font-size: 12px;
            box-sizing: border-box;
            font-weight: bold;
            z-index: 10;
        }

        .stats-container {
            display: flex;
            align-items: center;
        }

        .stats-container span {
            margin-right: 15px;
            color: #00ff00;
        }

        .label {
            color: #006600;
        }

        .close-btn {
            color: #004400;
            font-size: 10px;
            font-weight: bold;
            cursor: pointer;
            margin-right: 15px;
            padding: 2px 8px;
            border: 1px solid #004400;
            text-decoration: none;
            transition: all 0.2s ease;
            background-color: transparent;
        }

        .close-btn:hover {
            background-color: #004400;
            color: #000000;
        }

        #global-time {
            font-size: 12px;
            color: #00ff00;
        }

        /* --- Conversation area (center) --- */
        #chat-display {
            position: absolute;
            top: 25px;
            bottom: 40px;
            left: 0;
            right: 0;
            width: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 10px;
            box-sizing: border-box;
            background-color: #000000;
            scrollbar-width: thin;
            scrollbar-color: #003300 #000000;
        }

        #chat-display::-webkit-scrollbar {
            width: 8px;
        }

        #chat-display::-webkit-scrollbar-track {
            background: #000000;
        }

        #chat-display::-webkit-scrollbar-thumb {
            background: #003300;
            border-radius: 4px;
        }

        #chat-display::-webkit-scrollbar-thumb:hover {
            background: #004400;
        }

        /* --- Message formatting --- */
        .line {
            margin-bottom: 4px;
            line-height: 1.4;
            display: block;
            word-wrap: break-word;
            word-break: break-word;
            white-space: pre-wrap;
        }

        .timestamp {
            color: #004400;
            font-size: 0.8em;
            margin-right: 8px;
        }

        .username {
            color: #00ff00;
            font-weight: bold;
        }

        .system-msg {
            color: #008800;
            font-style: italic;
        }

        .error-msg {
            color: #aa0000;
            font-weight: bold;
        }

        .success-msg {
            color: #00ff00;
            text-shadow: 0 0 5px #003300;
            font-weight: bold;
        }

        .cmd {
            color: #aaaaaa;
        }

        .info-list {
            margin-left: 20px;
            border-left: 1px solid #004400;
            padding-left: 10px;
            color: #aaaaaa;
        }

        /* --- Autocomplete --- */
        #autocomplete-list {
            position: absolute;
            bottom: 40px;
            left: 10px;
            background-color: #001100;
            border: 1px solid #004400;
            border-radius: 4px;
            max-height: 230px;
            overflow-y: auto;
            display: none;
            z-index: 100;
            min-width: 250px;
            box-shadow: 0 4px 8px rgba(0, 255, 0, 0.1);
        }

        #autocomplete-list::-webkit-scrollbar {
            width: 6px;
        }

        #autocomplete-list::-webkit-scrollbar-track {
            background: #000000;
        }

        #autocomplete-list::-webkit-scrollbar-thumb {
            background: #003300;
            border-radius: 3px;
        }

        .autocomplete-item {
            padding: 8px 12px;
            cursor: pointer;
            color: #00aa00;
            border-bottom: 1px solid #003300;
            font-size: 13px;
            transition: background-color 0.2s ease;
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item:hover,
        .autocomplete-item.selected {
            background-color: #004400;
            color: #00ff00;
        }

        .autocomplete-item-cmd {
            color: #00ff00;
            font-weight: bold;
        }

        .autocomplete-item-desc {
            color: #006600;
            font-size: 11px;
            margin-left: 8px;
        }

        /* --- Input bar --- */
        #input-area {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            height: 40px;
            background-color: #000500;
            border-top: 1px solid #004400;
            display: flex;
            align-items: center;
            padding: 0 10px;
            box-sizing: border-box;
        }

        #prompt {
            color: #00ff00;
            margin-right: 10px;
            font-weight: bold;
            white-space: nowrap;
        }

        #command-input {
            flex-grow: 1;
            background: transparent;
            border: none;
            color: #00ff00;
            font-family: 'Courier New', Courier, monospace;
            font-size: 14px;
            outline: none;
            width: 100%;
        }

        #command-input::placeholder {
            color: #004400;
        }

        .owner-badge {
            color: #FFD700;
            font-weight: bold;
            font-size: 0.9em;
            margin-right: 5px;
            vertical-align: middle;
        }

        /* --- Loading Indicator --- */
        .loading-indicator {
            display: inline-block;
            color: #00ff00;
            font-weight: bold;
        }

        .loading-indicator::after {
            content: '';
            animation: dots 1.5s steps(4, end) infinite;
        }

        @keyframes dots {

            0%,
            20% {
                content: '';
            }

            40% {
                content: '.';
            }

            60% {
                content: '..';
            }

            80%,
            100% {
                content: '...';
            }
        }
    </style>
</head>

<body>

    <div id="top-bar" style="<?php echo $isUserBanned ? 'background: #220000; border-bottom: 1px solid #ff0000;' : ''; ?>">
        <div class="stats-container">
            <?php if ($isUserBanned): ?>
                <a href="../blocked" id="close-btn-banned" class="close-btn" title="Restricted Access" style="color: #ff0000; border-color: #ff0000;">RESTRICTED</a>
                <span class="label" style="color: #ff0000;">STATUS:</span> <span id="ui-user-banned" style="color: #ff6666;">BANNED</span>
                <span class="label" style="color: #ff0000;">USER:</span> <span id="ui-sub-banned"><?php echo $safeUsername; ?></span>
                <span class="label" style="color: #ff0000;">ACCESS:</span> <span id="ui-points-banned" style="color: #ff6666;">LIMITED</span>
            <?php else: ?>
                <a href="../index" id="close-btn-active" class="close-btn" title="Logout / Exit">EXIT</a>
                <span class="label">USER:</span> <span id="ui-user-active"><?php echo $safeUsername; ?></span>
                <span class="label">SUB:</span> <span id="ui-sub-active"><?php echo $safeSubscription; ?></span>
                <span class="label">PTS:</span> <span id="ui-points-active"><?php echo $safePoints; ?></span>
            <?php endif; ?>
        </div>
        <div id="global-time" style="<?php echo $isUserBanned ? 'color: #ff0000;' : ''; ?>">00:00:00 UTC</div>
    </div>

    <div id="chat-display">
        <div class="line system-msg"><span style="color:#33ff00">ROOTS SECURE MESSAGING</span></div>
        <div class="line system-msg">System Status : [ <span style="color:#33ff00">ONLINE</span> ]</div>
        <div class="line system-msg">Security Level : [ <span style="color:#33ff00">MAXIMUM P2P</span> ]</div>
        <div class="line system-msg">Assistant Admin: [ <span style="color:#0099ff">ID 48</span> ]</div>
        <div class="line system-msg">Group ROOTS Global: [ <span style="color:#0099ff">ID 10019</span> ]</div>
        <div class="line system-msg">Type <span style="color:#33ff00">/help</span> for available commands.</div>
        <br>
    </div>

    <div id="autocomplete-list"></div>

    <div id="input-area">
        <span id="prompt"><?php echo $safeUsername; ?>@radio:~$</span>
        <input type="text" id="command-input" autocomplete="off" autofocus>
    </div>

    <script nonce="<?php echo \ROOTS\Middleware\SecurityHeadersMiddleware::getNonce(); ?>">
        "use strict";

        // DOM Elements
        const chatBox = document.getElementById('chat-display');
        const inputField = document.getElementById('command-input');
        const timeDisplay = document.getElementById('global-time');
        const autocompleteList = document.getElementById('autocomplete-list');
        const promptUser =
            <?php echo json_encode(
                $safeUsername,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
            ); ?>;

        // State Management
        let currentChatId = null;
        let currentChatType = null; // 'user' or 'group'
        let pollInterval = null;
        let lastMessageId = 0;
        let csrfToken = null; // Will be fetched from API
        let isLoading = false;
        let messageCache = new Map(); // Cache for messages to avoid duplicates

        // Commands definition
        const commands = [{
            cmd: '/help',
            desc: 'Show command list'
        },
        {
            cmd: '/listuser',
            desc: 'Show friends & groups'
        },
        {
            cmd: '/adduser',
            desc: 'Add Friend or Join Group (Usage: /adduser <ID/Name>)'
        },
        {
            cmd: '/request',
            desc: 'Show pending requests'
        },
        {
            cmd: '/creategroup',
            desc: 'Create new group (Usage: /creategroup <Name>)'
        },
        {
            cmd: '/delete',
            desc: 'Delete friend or leave/delete group (Usage: /delete <ID>)'
        },
        {
            cmd: '/clear',
            desc: 'Clear screen (or /clear <ID> to wipe chat history)'
        }
        ];

        let autocompleteState = {
            visible: false,
            selectedIndex: -1,
            filteredCommands: []
        };

        // --- Core Utilities ---

        /**
         * Update global time display
         */
        function updateTime() {
            try {
                const now = new Date();
                const timeString = now.toISOString().split('T')[1].split('.')[0] + ' UTC';
                timeDisplay.innerText = timeString;
            } catch (error) {
                console.error('Time update error:', error);
            }
        }

        // Initialize time updates
        setInterval(updateTime, 1000);
        updateTime();

        /**
         * XSS Prevention: Escape HTML characters
         * @param {string} text - Text to escape
         * @returns {string} - Escaped text
         */
        function escapeHtml(text) {
            if (text === null || text === undefined) {
                return "";
            }

            if (typeof text !== 'string') {
                text = String(text);
            }

            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        /**
         * Print message to chat display
         * @param {string} text - Message text
         * @param {string} className - CSS class name
         * @param {boolean} isHtml - Whether text contains safe HTML
         */
        function print(text, className = 'line', isHtml = false) {
            try {
                const div = document.createElement('div');
                div.className = 'line ' + className;

                const time = new Date().toLocaleTimeString('en-GB', {
                    hour12: false
                });
                const timestamp = `<span class="timestamp">[${escapeHtml(time)}]</span>`;

                // If isHtml is false, we sanitize the text. If true, we assume caller sanitized it.
                const content = isHtml ? text : escapeHtml(text);

                div.innerHTML = timestamp + content;
                chatBox.appendChild(div);

                // Smooth scroll to bottom
                chatBox.scrollTop = chatBox.scrollHeight;
            } catch (error) {
                console.error('Print error:', error);
            }
        }

        /**
         * Show loading indicator
         */
        function showLoading() {
            if (!isLoading) {
                isLoading = true;
                const loadingDiv = document.createElement('div');
                loadingDiv.id = 'loading-indicator';
                loadingDiv.className = 'line loading-indicator';
                loadingDiv.innerHTML = '<span class="timestamp">[' + new Date().toLocaleTimeString('en-GB', {
                    hour12: false
                }) + ']</span>Processing';
                chatBox.appendChild(loadingDiv);
                chatBox.scrollTop = chatBox.scrollHeight;
            }
        }

        /**
         * Hide loading indicator
         */
        function hideLoading() {
            if (isLoading) {
                isLoading = false;
                const loadingDiv = document.getElementById('loading-indicator');
                if (loadingDiv) {
                    loadingDiv.remove();
                }
            }
        }

        // --- API Interaction ---

        /**
         * Fetch CSRF Token immediately on load
         */
        function initSession() {
            const formData = new FormData();
            formData.append('action', 'get_csrf_token');

            fetch('terminal_api', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(res => {
                    if (res && res.status === 'success' && res.data && res.data.csrf_token) {
                        csrfToken = res.data.csrf_token;
                    } else {
                        print("Security initialization failed.", "error-msg");
                        console.error('CSRF token fetch failed:', res);
                    }
                })
                .catch(error => {
                    console.error('Init session error:', error);
                    print("Connection failed during initialization.", "error-msg");
                });
        }

        // Initialize session on page load
        initSession();

        /**
         * Fetch API with error handling and retry logic
         * @param {string} action - API action
         * @param {object} data - Request data
         * @param {function} callback - Success callback
         */
        function fetchAPI(action, data, callback) {
            const formData = new FormData();
            formData.append('action', action);

            // Append CSRF token if available
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            // Append all data parameters
            for (const key in data) {
                if (data.hasOwnProperty(key)) {
                    formData.append(key, data[key]);
                }
            }

            fetch('terminal_api', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(async response => {
                    if (!response.ok) {
                        const text = await response.text();
                        console.error('API Error Response:', text);

                        let errorMsg = null;
                        try {
                            const json = JSON.parse(text);
                            if (json.message) errorMsg = json.message;
                        } catch (e) {
                            // ignore json parse error
                        }

                        if (errorMsg) {
                            throw new Error(errorMsg);
                        }

                        throw new Error("HTTP error! status: " + response.status);
                    }
                    return response.json();
                })
                .then(res => {
                    hideLoading();

                    if (!res || typeof res !== 'object') {
                        throw new Error('Invalid response format');
                    }

                    if (res.status === 'success') {
                        if (typeof callback === 'function') {
                            callback(res.data || {}, res.message || '');
                        }
                    } else {
                        const errorMessage = res.message || 'Unknown error occurred';
                        print(errorMessage, 'error-msg');

                        // Handle specific error cases
                        if (errorMessage.includes("Session expired") || errorMessage.includes("Unauthorized")) {
                            print("Redirecting to login...", 'system-msg');
                            setTimeout(() => {
                                window.location.href = '../login';
                            }, 2000);
                        }

                        if (errorMessage.includes("Access denied")) {
                            exitChat();
                        }

                        if (errorMessage.includes("Invalid or missing CSRF token")) {
                            print("Security token expired. Refreshing...", 'system-msg');
                            initSession();
                        }
                    }
                })
                .catch(err => {
                    hideLoading();
                    if (err.message && err.message.includes("Too many requests")) {
                        // Handle rate limiting gracefully - don't spam the terminal
                        console.warn('API Rate Limited:', err.message);
                        return;
                    }
                    console.error('API Error:', err);
                    // Use the error message from the exception if available and safe
                    let msg = err.message || "Network error or API unavailable. Please try again.";
                    if (msg === "Failed to fetch" || msg === "Network response was not ok") {
                        msg = "Network error or API unavailable. Please try again.";
                    }
                    print(msg, 'error-msg');
                });
        }

        // --- Command Execution ---

        /**
         * Execute user command or send message
         * @param {string} cmdStr - Command string
         */
        function execute(cmdStr) {
            if (!cmdStr || typeof cmdStr !== 'string') {
                return;
            }

            cmdStr = cmdStr.trim();

            if (!cmdStr) {
                return;
            }

            const parts = cmdStr.split(/\s+/);
            const command = parts[0].toLowerCase();
            const arg = parts.slice(1).join(' ');

            // If inside a chat and input is not a command, send message
            if (currentChatId && !cmdStr.startsWith('/')) {
                sendMessage(cmdStr);
                return;
            }

            // Print the command locally
            print(cmdStr, 'cmd');

            switch (command) {
                case '/help':
                    print("--- SYSTEM COMMANDS ---", 'system-msg');
                    commands.forEach(c => {
                        print(`${c.cmd} : ${c.desc}`, 'info-list');
                    });
                    break;

                case '/clear':
                    if (arg) {
                        // Delete history
                        if (confirm("Are you sure you want to permanently delete this conversation?")) {
                            // Only allow clearing current open chat
                            if (currentChatId && arg == currentChatId) {
                                showLoading();
                                fetchAPI('delete_conversation', {
                                    chat_id: currentChatId,
                                    type: currentChatType
                                }, (data, msg) => {
                                    print(msg, 'success-msg');
                                    chatBox.innerHTML = '';
                                    exitChat();
                                });
                            } else {
                                print("To delete a conversation, please stay in the conversation and then try again.", 'error-msg');
                            }
                        }
                    } else {
                        // Clear screen only
                        chatBox.innerHTML = '';
                        print("Screen cleared.", 'system-msg');
                    }
                    break;

                case '/listuser':
                    showLoading();
                    fetchAPI('list_users', {}, (list) => {
                        if (!list || !Array.isArray(list)) {
                            print("Error retrieving contacts list.", 'error-msg');
                            return;
                        }

                        if (list.length === 0) {
                            print("No contacts or groups found.", 'system-msg');
                        } else {
                            list.forEach(item => {
                                const type = item.type === 'group' ? '[GRP]' : '[USR]';
                                const chatId = escapeHtml(String(item.chat_id));
                                const username = escapeHtml(item.username);
                                print(`${type} ID: <span style="color:#33ff00">${chatId}</span> | ${username}`,
                                    'info-list', true);
                            });
                            print("Type the ID number to open chat.", 'system-msg');
                        }
                    });
                    break;

                case '/adduser':
                    if (!arg) {
                        print("Usage: /adduser <username/id>", 'error-msg');
                        return;
                    }

                    showLoading();
                    fetchAPI('add_user', {
                        target: arg
                    }, (data, msg) => {
                        print(msg, 'success-msg');
                    });
                    break;

                case '/request':
                    if (arg) {
                        // Accept request
                        if (confirm("Accept friend request from user ID: " + arg + "?")) {
                            showLoading();
                            fetchAPI('accept_request', {
                                target_id: arg
                            }, (data, msg) => {
                                print(msg, 'success-msg');
                            });
                        }
                    } else {
                        // List requests
                        showLoading();
                        fetchAPI('show_requests', {}, (requests) => {
                            if (!requests || !Array.isArray(requests)) {
                                print("Error retrieving requests.", 'error-msg');
                                return;
                            }

                            if (requests.length === 0) {
                                print("No pending requests.", 'system-msg');
                            } else {
                                requests.forEach(req => {
                                    const senderId = escapeHtml(String(req.sender_id));
                                    const username = escapeHtml(req.username);
                                    print(`Request from: [ID: ${senderId}] ${username}`, 'info-list', true);
                                });
                                print("Type '/request <ID>' to accept.", 'system-msg');
                            }
                        });
                    }
                    break;

                case '/delete':
                    if (!arg) {
                        print("Usage: /delete <ID>", 'error-msg');
                        return;
                    }

                    if (confirm("Are you sure you want to remove contact ID: " + arg + "?\n(If you are the owner of a group, it will be permanently deleted.)")) {
                        showLoading();
                        fetchAPI('delete_contact', {
                            target_id: arg
                        }, (data, msg) => {
                            print(msg, 'success-msg');
                            // Refresh list automatically
                            setTimeout(() => {
                                execute('/listuser');
                                // If we were in that chat, exit
                                if (currentChatId == arg) {
                                    exitChat();
                                }
                            }, 500);
                        });
                    }
                    break;

                case '/creategroup':
                    if (!arg) {
                        print("Usage: /creategroup <name>", 'error-msg');
                        return;
                    }

                    if (confirm("Create new group named: " + arg + "?")) {
                        showLoading();
                        fetchAPI('create_group', {
                            name: arg
                        }, (data, msg) => {
                            print(msg, 'success-msg');
                            // Refresh list automatically
                            setTimeout(() => {
                                execute('/listuser');
                            }, 500);
                        });
                    }
                    break;

                default:
                    // Check if command is a chat ID (number)
                    if (!isNaN(command) && !cmdStr.startsWith('/')) {
                        switchChat(command);
                    } else {
                        print(`Unknown command: ${escapeHtml(command)}`, 'error-msg');
                        print("Type /help for available commands.", 'system-msg');
                    }
            }
        }

        // --- Chat Functions ---

        /**
         * Switch to a different chat
         * @param {string|number} chatId - Chat ID to switch to
         */
        function switchChat(chatId) {
            if (!chatId) {
                print("Invalid chat ID.", 'error-msg');
                return;
            }

            showLoading();

            // Find chat details first
            fetchAPI('list_users', {}, (list) => {
                if (!list || !Array.isArray(list)) {
                    print("Failed to retrieve chat list.", 'error-msg');
                    return;
                }

                let target = list.find(i => i.chat_id == chatId);

                // If not found by chat_id, allow numeric index (1-based) lookup
                if (!target) {
                    const idx = parseInt(String(chatId), 10);
                    if (!isNaN(idx) && idx >= 1 && idx <= list.length) {
                        target = list[idx - 1];
                        print(`Interpreting ${escapeHtml(String(chatId))} as list index ${idx}`, 'system-msg');
                    }
                }

                if (target) {
                    currentChatId = target.chat_id;
                    currentChatType = target.type;
                    lastMessageId = 0;
                    messageCache.clear();

                    print(`Connected to ${escapeHtml(target.type)}: ${escapeHtml(target.username)}`, 'system-msg');

                    // Clear poll interval if exists
                    if (pollInterval) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                    }

                    // Initial Fetch
                    fetchMessages();

                    // Start Polling (every 3 seconds)
                    pollInterval = setInterval(fetchMessages, 3000);

                    // Update input placeholder
                    inputField.placeholder = `Messaging ${target.username}...`;
                } else {
                    print(`Chat ID ${escapeHtml(String(chatId))} not found in your list.`, 'error-msg');
                }
            });
        }

        /**
         * Exit current chat
         */
        function exitChat() {
            currentChatId = null;
            currentChatType = null;
            lastMessageId = 0;
            messageCache.clear();

            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }

            inputField.placeholder = "";
            print("Chat closed. Returned to main terminal.", 'system-msg');
        }

        /**
         * Fetch messages from current chat
         */
        function fetchMessages() {
            if (!currentChatId) {
                return;
            }

            let params = {
                chat_id: currentChatId,
                type: currentChatType
            };

            // Limit group messages to last 100
            if (currentChatType === 'group') {
                params.limit = 100;
            }

            fetchAPI('fetch_messages', params, (messages) => {
                if (!messages || !Array.isArray(messages)) {
                    console.error('Invalid messages response');
                    return;
                }

                // Ensure messages are sorted by ID
                messages.sort((a, b) => {
                    const idA = parseInt(a.id) || 0;
                    const idB = parseInt(b.id) || 0;
                    return idA - idB;
                });

                messages.forEach(msg => {
                    if (!msg || !msg.id) {
                        return;
                    }

                    const msgId = parseInt(msg.id);

                    if (msgId > lastMessageId && !messageCache.has(msgId)) {
                        messageCache.set(msgId, true);

                        const isFromMe = msg.is_from_me === true || msg.is_from_me === 1 || msg
                            .is_from_me === '1';
                        const unameClass = 'username';
                        const unameStr = isFromMe ? 'You' : (msg.username || 'Unknown');

                        // Construct HTML safe string
                        const safeContent = escapeHtml(msg.content || '');
                        const safeUser = escapeHtml(unameStr);

                        let prefix = '';
                        // Add owner badge if applicable
                        if (msg.role === 'owner') {
                            prefix = '<span class="owner-badge" title="Group Owner">★</span>';
                        }

                        const html = `${prefix}<span class="${unameClass}">${safeUser}:</span> ${safeContent}`;
                        print(html, 'line', true);

                        lastMessageId = Math.max(lastMessageId, msgId);
                    }
                });
            });
        }

        /**
         * Send message to current chat
         * @param {string} content - Message content
         */
        function sendMessage(content) {
            if (!currentChatId) {
                print("No active chat. Please select a chat first.", 'error-msg');
                return;
            }

            if (!content || content.trim() === '') {
                print("Cannot send empty message.", 'error-msg');
                return;
            }

            content = content.trim();

            if (content.length > 5000) {
                print("Message too long. Maximum 5000 characters.", 'error-msg');
                return;
            }

            fetchAPI('send_message', {
                chat_id: currentChatId,
                type: currentChatType,
                content: content
            }, (data, msg) => {
                // Message sent successfully, force fetch to display it
                fetchMessages();
            });
        }

        // --- Autocomplete Logic ---

        /**
         * Show autocomplete suggestions
         * @param {string} input - Current input value
         */
        function showAutocomplete(input) {
            if (!input || !input.startsWith('/')) {
                hideAutocomplete();
                return;
            }

            const term = input.toLowerCase().substring(1); // remove '/'

            autocompleteState.filteredCommands = commands.filter(c => {
                return c.cmd.toLowerCase().includes(term) || term === '';
            });

            if (autocompleteState.filteredCommands.length > 0) {
                autocompleteState.selectedIndex = 0;
                renderAutocomplete();
                autocompleteList.style.display = 'block';
                autocompleteState.visible = true;
            } else {
                hideAutocomplete();
            }
        }
        /**
         * Render autocomplete list
         */
        function renderAutocomplete() {
            autocompleteList.innerHTML = '';

            autocompleteState.filteredCommands.forEach((cmd, idx) => {
                const div = document.createElement('div');
                div.className = 'autocomplete-item' + (idx === autocompleteState.selectedIndex ? ' selected' : '');

                const safeCmd = escapeHtml(cmd.cmd);
                const safeDesc = escapeHtml(cmd.desc);

                div.innerHTML = `<span class="autocomplete-item-cmd">${safeCmd}</span> <span class="autocomplete-item-desc">${safeDesc}</span>`;

                div.onclick = () => {
                    selectAutocomplete(idx);
                };

                autocompleteList.appendChild(div);
            });
        }

        /**
         * Select autocomplete item
         * @param {number} idx - Index of item to select
         */
        function selectAutocomplete(idx) {
            if (idx < 0 || idx >= autocompleteState.filteredCommands.length) {
                return;
            }

            const cmd = autocompleteState.filteredCommands[idx];

            if (cmd) {
                inputField.value = cmd.cmd + ' ';
                hideAutocomplete();
                inputField.focus();
            }
        }

        /**
         * Hide autocomplete list
         */
        function hideAutocomplete() {
            autocompleteList.style.display = 'none';
            autocompleteState.visible = false;
            autocompleteState.selectedIndex = -1;
        }

        // --- Event Listeners ---

        /**
         * Handle keyboard input in command field
         */
        inputField.addEventListener('keydown', (e) => {
            // Autocomplete Navigation
            if (autocompleteState.visible) {
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    autocompleteState.selectedIndex = Math.max(0, autocompleteState.selectedIndex - 1);
                    renderAutocomplete();
                    return;
                }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    autocompleteState.selectedIndex = Math.min(
                        autocompleteState.filteredCommands.length - 1,
                        autocompleteState.selectedIndex + 1
                    );
                    renderAutocomplete();
                    return;
                }

                if (e.key === 'Tab' || e.key === 'Enter') {
                    e.preventDefault();
                    selectAutocomplete(autocompleteState.selectedIndex);
                    return;
                }

                if (e.key === 'Escape') {
                    e.preventDefault();
                    hideAutocomplete();
                    return;
                }
            }

            // Command Execution on Enter
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = inputField.value;

                if (val && val.trim()) {
                    execute(val);
                    inputField.value = '';
                    hideAutocomplete();
                }
            }

            // Exit chat on Escape (when not in autocomplete)
            if (e.key === 'Escape' && !autocompleteState.visible && currentChatId) {
                exitChat();
            }
        });

        /**
         * Handle input changes for autocomplete
         */
        inputField.addEventListener('input', () => {
            const val = inputField.value;

            if (val.startsWith('/') && !currentChatId) {
                showAutocomplete(val);
            } else {
                hideAutocomplete();
            }
        });

        /**
         * Focus input on click anywhere
         */
        document.body.addEventListener('click', (e) => {
            if (e.target !== inputField &&
                !e.target.classList.contains('autocomplete-item') &&
                !autocompleteList.contains(e.target)) {
                inputField.focus();
            }
        });

        /**
         * Prevent context menu for better terminal feel
         */
        document.addEventListener('contextmenu', (e) => {
            e.preventDefault();
        });

        /**
         * Handle window focus to ensure proper state
         */
        window.addEventListener('focus', () => {
            inputField.focus();
        });

        /**
         * Cleanup on page unload
         */
        window.addEventListener('beforeunload', () => {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }

            messageCache.clear();
        });

        /**
         * Handle visibility change to pause/resume polling
         */
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Page is hidden, pause polling to save resources
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            } else {
                // Page is visible again, resume polling if in chat
                if (currentChatId && !pollInterval) {
                    fetchMessages(); // Fetch immediately
                    pollInterval = setInterval(fetchMessages, 3000);
                }
            }
        });

        // Focus input field on page load
        window.addEventListener('load', () => {
            inputField.focus();
        });

    </script>
