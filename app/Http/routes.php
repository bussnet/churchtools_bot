<?php

// Token for Telegram Webhook, so not erveryone can post Updates
$auth_token = 'eda1efc2daf44a12fbe7ddaa2a28a0283643a9db72e3bc33c8c6b45b161a9eac';


Route::any('/', function (\Illuminate\Http\Request $request) {
    Log::debug("/ with".print_r($request->all(), true)." from ". $request->ip());
    return response()->json([
        'status' => 'success'
    ]);
});

Route::any('/test', function (\Illuminate\Http\Request $request) {
    Log::debug("/test with" . print_r($request->all(), true) . " from " . $request->ip());
    return response()->json([
        'status' => 'success'
    ]);
});

Route::any('/add/{str}', function ($auth_token, \Illuminate\Http\Request $request) {
    $p = [
        'email' => $request->email,
        'host' => $request->host,
        'user' => $request->id,
        'token' => $request->token
    ];
    Log::debug("/add/$auth_token Params:" . print_r($p, true) . "from " . $request->ip());
    Storage::disk('local')->put('auth_tokens/' . $auth_token, json_encode($p));

    return response()->json([
        'status' => 'success'
    ]);
});


function pairCT($chat_id, $p) {
    $client = new GuzzleHttp\Client(['cookies' => true]);

    $result = $client->post('http://' . $p['host'] . '/index.php', [
        'query' => [
            'q' => 'login/ajax',
        ],
        'form_params' => [
            'func' => 'loginWithToken',
            'directtool' => 'telegrambot',
            'id' => $p['user'],
            'token' => $p['token'],
        ],
        'allow_redirects' => ['strict' => true] // user POST if redirect to https
    ]);

    $resp_body = $result->getBody()->getContents();
    Log::debug('result of logging in '.$p['host'].' for ' . $p['email'] . ': '. $resp_body);
    $json = json_decode($resp_body, true);
    if ($json['status'] != 'success')
        throw new Exception('could not login to CT with ID and token');


    $result = $client->post('http://' . $p['host'] . '/index.php', [
        'query' => [
            'q' => 'churchhome/ajax',
        ],
        'form_params' => [
            'func' => 'setTelegramChatId',
            'chatId' => $chat_id
        ],
        'allow_redirects' => ['strict' => true] // user POST if redirect to https
    ]);
    $resp_body = $result->getBody()->getContents();
    Log::debug('result of setting the telegramId for '.$p['email'].': '. $resp_body);
    $json = json_decode($resp_body, true);
    if ($json['status'] != 'success')
        throw new Exception('could not set telegramId');
}

/**
 * @param \Telegram\Bot\Objects\Update[] $updates
 * @return int last_update _id
 */
function processUpdates($updates) {
    Log::debug('Updates '. print_r($updates, true));
    foreach ($updates as $k => $update) {
        Log::debug('receive Update '. print_r($k, true).'=>'.print_r($update, true));
        /** @var \Telegram\Bot\Objects\Message $msg */
        $msg = $update->getMessage();
        $chat = $msg->getChat();
        $txt = $msg->getText();
        Log::debug('receive msg "' . $txt . '" from ' . $chat->getUsername());
        // check authcode
        if (strlen($txt) == 6) {
            $file = 'auth_tokens/' . $txt;
            if (Storage::disk()->exists($file)) {
                Log::debug('found token ' . $txt);
                pairCT($msg->getChat()->getId(), json_decode(Storage::disk()->get($file), true));
                @Storage::disk()->delete($file);
            }
        }
        $last_update = $update->getUpdateId();
    }
    return $last_update;
}

Route::any('/message/{chat_id}', function ($chat_id, \Illuminate\Http\Request $request) {
    $user = $request->id;
    $text = $request->text;
    Log::debug("/message/$chat_id user:" . $user . " with" . print_r($request->all(), true) . " from " . $request->ip());


//    $keyboard = [
//        ['Ja', 'Nein', 'Vielleicht'],
//    ];
//
//    $reply_markup = Telegram::replyKeyboardMarkup([
//        'keyboard' => $keyboard,
//        'resize_keyboard' => false,
//        'one_time_keyboard' => false
//    ]);

    $status = Telegram::sendMessage([
        'chat_id' => $chat_id,
        'text' => $text
//        'reply_markup' => $reply_markup
    ]);

    $response = [
        'status' => $status ? 'success' : 'failed'
    ];

    if (!$status) {
        $response['error'] = true;
        $response['message'] = 'could not send Message';
    }

    return response()->json($response);
});

Route::any('/webhook/{token}', function($token) use ($auth_token) {

    if ($token == 'enable') {
        // Don't forget to setup a POST route in your Laravel Project.
        $url = url('webhook', ['token' => $auth_token], true);
        $response = Telegram::setWebhook(['url' => $url]);
        Log::debug('enable Webhook to '.$url.': '.print_r($response,true));
        return 'OK';
    } elseif($token == 'pull') {
        Telegram::removeWebhook();
        $last_update_file = 'storage/last_update_id';
        $last_update = intval(@Storage::disk()->get($last_update_file) ?: 0);
        /**
         * @var \Telegram\Bot\Objects\Update[] $updates
         */
        $updates = Telegram::getUpdates([
            'offset' => $last_update + 1
        ]);

        $last_update = processUpdates($updates);
        Storage::disk()->put('storage/last_update_id', $last_update);
    } elseif($token == 'disable') {
        // Don't forget to setup a POST route in your Laravel Project.
        $response = Telegram::removeWebhook();
        Log::debug('disable Webhook: ' . print_r($response, true));
        return 'OK';
    } elseif ($token == $auth_token) {
        // Put this inside either the POST route '/<token>/webhook' closure (see below) or
        $update = Telegram::getWebhookUpdates();
        Log::debug('receive Updates');
        processUpdates([$update]);
        return 'OK';
    }
});