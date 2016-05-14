<?php
/**
 * User: thorsten
 * Date: 14.05.16
 * Time: 17:30
 */

namespace ChurchToolsBot\Http\Controllers;



use Telegram\Bot\Objects\Message;

class TelegramBotController extends Controller{

	/**
	 * @var string VERY simple way of authentificate a request from the telegramserver via url
	 */
	protected $auth_token = 'eda1efc2daf44a12fbe7ddaa2a28a0283643a9db72e3bc33c8c6b45b161a9eac';

	/**
	 * @var string file for caching the last update_id if receive telegram updats via pull
	 */
	protected $last_update_file = 'storage/telegram_bot_last_update_id';

	/**
	 * @var string directory for saving the auth_tokens
	 */
	protected $auth_token_dir = 'auth_tokens/';

	/**
	 * start page - also for /test
	 * @param \Illuminate\Http\Request $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function homepage(\Illuminate\Http\Request $request) {
		\Log::debug("/ with", $request->all());
		return \response()->json([
			'status' => 'success'
		]);
	}

	/**
	 * add an auth_token with user_details for later pairing
	 * @param $auth_token
	 * @param \Illuminate\Http\Request $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function add($auth_token, \Illuminate\Http\Request $request) {
		$p = [
			'email' => $request->email,
			'host' => $request->host,
			'user' => $request->id,
			'token' => $request->token
		];
		\Log::info("receive token for pairing $auth_token", $p);
		\Storage::disk()->put($this->auth_token_dir . $auth_token, json_encode($p));

		return \response()->json([
			'status' => 'success'
		]);
	}

	/**
	 * receive a message from CT for the given $chat_id
	 * @param $chat_id
	 * @param \Illuminate\Http\Request $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function message($chat_id, \Illuminate\Http\Request $request) {
		$user = $request->id;
		$text = $request->text;
		\Log::debug("[$chat_id|" . $user . "] " . $text, $request->all());

		if ($this->sendMessage($chat_id, $text))
			return \response()->json(['status' => 'success']);

		return \response()->json([
			'status' => 'failed',
			'error' => true,
			'message' => 'Die Nachricht konnte nicht gesendet werden'
		]);
	}

	/**
	 * handle the updates from telegram server, or enable/disable the webhook
	 * @param $token
	 * @param $action
	 * @return \Illuminate\Contracts\Routing\ResponseFactory|string|\Symfony\Component\HttpFoundation\Response
	 */
	public function webhook($token, $action) {
		// security check
		if ($token != $this->auth_token) {
			return \response('Not Found', 404);
		}
		
		switch($action) {
			case 'push_updates': // call from telegram server
				$update = \Telegram::getWebhookUpdates();
				\Log::debug('receive Updates');
				$this->processUpdates([$update]);
				return \response('OK');
			case 'pull': // pull the updates from the server - call from cron to receive updates
				 // not pulling AND receive over webhook, so remove webhook
				\Telegram::removeWebhook();

				// get the last update_id from the last Pull
				$last_update = intval(@\Storage::disk()->get($this->last_update_file) ?: 0);
				\Log::debug('pull updates from ' . ($last_update + 1));

				/**
				 * @var \Telegram\Bot\Objects\Update[] $updates
				 */
				$updates = \Telegram::getUpdates([
					'offset' => $last_update + 1
				]);
	
				$last_update = $this->processUpdates($updates);

				 // save the last update_id for the next pull
				\Storage::disk()->put($this->last_update_file, $last_update);
				\Log::debug('pulled updates up to ' . $last_update, $updates);
				return \response('OK');
			case 'enable': // register the webhookurl on telegram server
				$url = url('webhook', ['token' => $this->auth_token, 'action' => 'push_updates'], true);
				$response = \Telegram::setWebhook(['url' => $url]);
				\Log::debug('enable Webhook to ' . $url);
				return \response('OK');
			case 'disable':
				$response = \Telegram::removeWebhook();
				\Log::debug('disable Webhook');
				return \response('OK');
			default:
				return \response('Action Not Found', 404);
		}
	}


	/**
	 * pair the given chat with the CT User
	 * @param $chat_id
	 * @param $p
	 * @throws \Exception
	 */
	protected function pairCT($chat_id, $p) {
		$client = new \GuzzleHttp\Client([
			'cookies' => true,
			'allow_redirects' => ['strict' => true] // use POST if redirect to https
		]);

		// Login to CT with the given AuthCode
		$result = $client->post('http://' . $p['host'] . '/index.php', [
			'query' => ['q' => 'login/ajax',],
			'form_params' => [
				'func' => 'loginWithToken',
				'directtool' => 'telegrambot',
				'id' => $p['user'],
				'token' => $p['token'],
			]
		]);
		$resp_body = $result->getBody()->getContents();
		$json = json_decode($resp_body, true);
		\Log::debug('CT Login to' . $p['host'] . ' with ' . $p['email'], $json);
		if ($json['status'] != 'success')
			throw new \Exception('could not login to CT with ID and token');


		// set the given chatID to the User
		$result = $client->post('http://' . $p['host'] . '/index.php', [
			'query' => ['q' => 'churchhome/ajax',],
			'form_params' => [
				'func' => 'setTelegramChatId',
				'chatId' => $chat_id
			]
		]);
		$resp_body = $result->getBody()->getContents();
		$json = json_decode($resp_body, true);
		\Log::debug('CT SetChatId ' . $chat_id . ' for ' . $p['email'], $json);
		if ($json['status'] != 'success')
			throw new \Exception('could not set telegramId');
	}

	/**
	 * process the given updates from telegram server
	 * @param \Telegram\Bot\Objects\Update[] $updates
	 * @return int last_update _id
	 * @throws \Exception
	 */
	protected function processUpdates($updates) {
		$last_update = 0;
		\Log::debug('process ' . count($updates) . ' Updates');
		foreach ($updates as $k => $update) {
			\Log::debug('receive Update ' . $k, $update->all());
			/** @var \Telegram\Bot\Objects\Message $msg */
			$msg = $update->getMessage();
			$chat = $msg->getChat();
			$txt = $msg->getText();
			\Log::info('receive msg "' . $txt . '" from ' . $chat->getUsername());

			// check authcode
			if (strlen($txt) == 6) {
				$file = $this->auth_token_dir . $txt;
				if (\Storage::disk()->exists($file)) {
					\Log::info('found AuthToken ' . $txt . ' - link with chatId ' . $msg->getChat()->getId());
					try {
						$this->pairCT($msg->getChat()->getId(), json_decode(\Storage::disk()->get($file), true));
						@\Storage::disk()->delete($file);
						$this->sendMessage($msg->getChat()->getId(), 'Danke. Die Verbindung mit Churchtools ist erfolgreich hergestllt. Du Empfängst ab jetzt Telegram Nachrichten von Churchtools. Wichtig ist, das du diesen Chat nicht schließt.');
					} catch (\Exception $e) {
						$this->sendMessage($msg->getChat()->getId(), 'Es ist leider ein Fehler aufgetreten. Versuche es erneut oder wende dich an deinen ChurchTools Administrator');
					}
				}
			}
			$last_update = $update->getUpdateId();
		}
		return $last_update;
	}

	/**
	 * send a message to a telegram chat
	 * @param $chat_id
	 * @param $msg
	 * @return Message
	 */
	protected function sendMessage($chat_id, $msg) {
		/** @var Message $msg */
		$msg = \Telegram::sendMessage([
			'chat_id' => $chat_id,
			'text' => $msg
		]);

		return $msg->getStatus();
	}

}