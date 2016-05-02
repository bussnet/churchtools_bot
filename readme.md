# Telegram Bot for Churchtools

This is a simple TelegramBot implementaion for ChurchTools.

Very rough development (everything in routes.php with closures) waiting for further development.

This Bot is based on [Laravel](http://laravel.com/docs), the best PHP-Framework under the sun. 


## Install

    git clone https://github.com/bussnet/churchtools_bot
    
    cd churchtools_bot
    
    # Requires [composer](https://getcomposer.org/doc/)
    # curl -sS https://getcomposer.org/installer | sudo php --
    composer install
    
    # point the webserver with PHP to churchtools_bot/public
    
    # define your webroot in system/includes/constants.php in churchtools Dir
    define('TELEGRAMBOTURL', 'http://your-host.de/churchtools_bot');


## Contributing

Every commit/pull request is welcome


## License

The churchtools_bot is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).
