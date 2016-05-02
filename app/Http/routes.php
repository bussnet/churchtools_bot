<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

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
        ]
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
        ]
    ]);
    $resp_body = $result->getBody()->getContents();
    Log::debug('result of setting the telegramId for '.$p['email'].': '. $resp_body);
    $json = json_decode($resp_body, true);
    if ($json['status'] != 'success')
        throw new Exception('could not set telegramId');
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


Route::any('/pull', function (\Illuminate\Http\Request $request) {
    Telegram::removeWebhook();
    $last_update = 644255974;
    /**
     * @var \Telegram\Bot\Objects\Update[] $updates
     */
    $updates = Telegram::getUpdates([
        'offset' => $last_update + 1
    ]);

    foreach ($updates as $k => $update) {
        /** @var \Telegram\Bot\Objects\Message $msg */
        $msg = $update->getMessage();
        $chat = $msg->getChat();
        $txt = $msg->getText();
        Log::debug('receive msg "'.$txt.'" from '.$chat->getUsername());
        // check authcode
        if (strlen($txt) == 6) {
            $file = 'auth_tokens/' . $txt;
            if (Storage::disk()->exists($file)) {
                Log::debug('found token '.$txt);
                try {
                    pairCT($msg->getChat()->getId(), json_decode(Storage::disk()->get($file), true));
                    @Storage::disk()->delete($file);
                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 'failed',
                        'error' => true,
                        'message' => $e->getMessage()
                    ]);
                }
            }
        }

    }
//    Telegram::setWebhook();


    return response()->json([
        'status' => 'success'
    ]);
});




/*
Pairing
    $d = file_get_contents_curl_post(TELEGRAMBOTURL . "/add/$str?email=$user->email&host=" . $_SERVER["SERVER_NAME"] . "&id=$user->id&token=$token");

sendMessage
  $result = file_get_contents_curl_post("http://churchtools.de:8882/message/$p->telegramid?id=".$userId, $result);

  $data = json_decode($result);


  if ($data == null) {
    ct_log("Keine Info vom Telegram Bot erhalten.", 1);
  }
  if ($data->status!="success") {
    ct_log("Fehler vom TelegramBot erhalten: $result", 1);
  }
  db_query('INSERT INTO {cc_mail_queue} (receiver, sender, subject, body, htmlmail_yn, priority,
               modified_date, modified_pid, send_date, error, reading_count)
            VALUES (:receiver, :sender, :subject, :body, :htmlmail_yn, :priority,
               :modified_date, :modified_pid, :send_date, :error, :reading_count)',
      array(":receiver" => "$p->vorname $p->name",
          ":sender" => "Admin-Mail",
          ":subject" => "<i>Telegram: </i>" . shorten_string($message, 30),
          ":body" => $message,
          ":htmlmail_yn" => 0,
          ":priority" => 1,
          ":modified_date" => current_date(),
          ":modified_pid" => $user->id,
          ":send_date" => current_date(),
          ":error" => $data->status!="success",
          ":reading_count" => 0,
      ));

 */