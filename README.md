# phergie/phergie-irc-plugin-react-nickserv

[Phergie](http://github.com/phergie/phergie-irc-bot-react/) plugin for interacting with the NickServ agent to authenticate the bot's identity.

[![Build Status](https://secure.travis-ci.org/phergie/phergie-irc-plugin-react-nickserv.png?branch=master)](http://travis-ci.org/phergie/phergie-irc-plugin-react-nickserv)

## Install

The recommended method of installation is [through composer](http://getcomposer.org).

```JSON
{
    "require": {
        "phergie/phergie-irc-plugin-react-nickserv": "~2"
    }
}
```

See Phergie documentation for more information on
[installing and enabling plugins](https://github.com/phergie/phergie-irc-bot-react/wiki/Usage#plugins).

## Configuration

```php
'plugins' => array(
    new \Phergie\Irc\Plugin\React\NickServ\Plugin(array(

        // Required: password used to authenticate with NickServ
        'password' => 'YOUR-NICKSERV-PASSWORD-HERE',

        /* Everything else is optional! */

        // NickServ's nickname
        'botnick' => 'NickServ',

        // Whether or not to attempt to "ghost" the primary nick if it's in use
        'ghost' => false,

        // Regex pattern matching a NickServ notice asking for identification
        'identifypattern' => '/This nickname is registered/',

        // Regex pattern matching a NickServ notice indicating a successful login
        'loggedinpattern' => '/You are now identified/',

        // Regex pattern matching a NickServ notice indicating the nickname has been ghosted
        'ghostpattern' => '/has been ghosted/',
    )),

    // If 'ghost' is true, an alternative nickname plugin is required. See "Ghosting" below.
    new \PSchwisow\Phergie\Plugin\AltNick\Plugin(array(
        'nicks' => /* ... */
    )),
)
```

## Ghosting

This plugin has a 'ghost' feature: if the configuration option is set, and your primary nickname is in use
when you join the server, it will ask NickServ to kill your primary nickname and then switch to it if the
command is successful.

If you want to use this feature, then note that the NickServ plugin will not automatically change your nickname
for you if your primary nickname is in use. **You will need to use a different plugin**, such as
[AltNick](https://github.com/PSchwisow/phergie-irc-plugin-react-altnick), to provides the server with an
alternative nickname when your primary nickname is in use.

If your primary nickname is in use, and no plugin provides the server with an alternative nickname, then the server
will close the connection before the NickServ plugin can attempt to regain your primary nickname.

## Events

This plugin emits the following event:

Event name | Callback parameters | Emitted on
-----------|---------------------|-----------
`nickserv.identified` | <ul><li>`\Phergie\Irc\ConnectionInterface $connection`</li><li>`\Phergie\Irc\Bot\React\EventQueueInterface $queue`</li></ul> | Successful NickServ login

## Tests

To run the unit test suite:

```
curl -s https://getcomposer.org/installer | php
php composer.phar install
./vendor/bin/phpunit
```

## License

Released under the BSD License. See `LICENSE`.
