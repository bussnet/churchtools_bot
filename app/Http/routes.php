<?php

Route::group(['middleware' => 'api'], function () {
    Route::any('/', "TelegramBotController@homepage");
    Route::any('/test', "TelegramBotController@homepage");
    Route::any('/add/{auth_token}', "TelegramBotController@add");
    Route::any('/message/{chat_id}', "TelegramBotController@message");
    Route::any('/webhook/{token}/{action}', "TelegramBotController@webhook");
});
