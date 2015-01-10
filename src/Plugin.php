<?php
/**
 * Phergie (http://phergie.org)
 *
 * @link https://github.com/phergie/phergie-irc-plugin-react-nickserv for the canonical source repository
 * @copyright Copyright (c) 2008-2014 Phergie Development Team (http://phergie.org)
 * @license http://phergie.org/license New BSD License
 * @package Phergie\Irc\Plugin\React\NickServ
 */

namespace Phergie\Irc\Plugin\React\NickServ;

use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use Phergie\Irc\Event\UserEventInterface as UserEvent;
use Phergie\Irc\Event\ServerEventInterface as ServerEvent;

/**
 * Plugin for interacting with the NickServ agent to authenticate the bot's
 * identity.
 *
 * @category Phergie
 * @package Phergie\Irc\Plugin\React\NickServ
 */
class Plugin extends AbstractPlugin
{
    /**
     * Password used to authenticate the bot's identity with the NickServ agent
     *
     * @var string
     */
    protected $password;

    /**
     * Name of the NickServ agent
     *
     * @var string
     */
    protected $botNick = 'NickServ';

    /**
     * Accepts plugin configuration.
     *
     * Supported keys:
     *
     * password - required password used to authenticate the bot's identity to
     * the NickServ agent
     *
     * @param array $config
     */
    public function __construct(array $config = array())
    {
        $this->password = $this->getPassword($config);
    }

    /**
     * Indicate that the plugin monitors events involved in asserting and
     * authenticating the bot's identity, including related interactions with
     * the NickServ agent.
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            'irc.received.notice' => 'handleNotice',
            'irc.received.quit' => 'handleQuit',
            'irc.received.nick' => 'handleNick',
            'irc.received.err_nicknameinuse' => 'handleNicknameInUse',
        );
    }

    /**
     * Responds to authentication requests and notifications of ghost
     * connections being killed from NickServ.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleNotice(UserEvent $event, Queue $queue)
    {
        // Ignore notices that aren't from the NickServ agent
        if (strcasecmp($event->getNick(), $this->botNick) !== 0) {
            return;
        }

        $params = $event->getParams();
        $message = $params['text'];

        // Authenticate the bot's identity for authentication requests
        $nick = $event->getConnection()->getNickname();
        $pattern = '/This nickname is registered/';
        if (preg_match($pattern, $message)) {
            $message = 'IDENTIFY ' . $nick . ' ' . $this->password;
            return $queue->ircPrivmsg($this->botNick, $message);
        }

        // Switch nicks for notifications of ghost connections being killed
        $pattern = '/^.*' . preg_quote($nick) . '.* has been ghosted/';
        if (preg_match($pattern, $message)) {
            return $queue->ircNick($nick);
        }
    }

    /**
     * Reclaims the bot's nick if the user using it quits.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleQuit(UserEvent $event, Queue $queue)
    {
        $nick = $event->getConnection()->getNickname();
        if (strcasecmp($nick, $event->getNick()) === 0) {
            return $queue->ircNick($nick);
        }
    }

    /**
     * Changes the nick associated with the bot in local memory when a change
     * to it is successfully registed with the server.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleNick(UserEvent $event, Queue $queue)
    {
        $connection = $event->getConnection();
        if (strcasecmp($event->getNick(), $connection->getNickname()) === 0) {
            $params = $event->getParams();
            $connection->setNickname($params['nickname']);
        }
    }

    /**
     * Kills ghost connections. 
     *
     * @param \Phergie\Irc\Event\ServerEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleNicknameInUse(ServerEvent $event, Queue $queue)
    {
        // Change nicks so NickServ will allow further interaction
        $nick = $event->getConnection()->getNickname();
        $queue->ircNick($nick . '_');

        // Attempt to kill the ghost connection
        $message = 'GHOST ' . $nick . ' ' . $this->password;
        $queue->ircPrivmsg($this->botNick, $message);
    }

    /**
     * Extracts the password used to authenticate with the NickServ agent from
     * configuration.
     *
     * @param array $config
     * @return string
     * @throws \DomainException password is unspecified or not a string
     */
    protected function getPassword(array $config)
    {
        if (empty($config['password']) || !is_string($config['password'])) {
            throw new \DomainException(
                'password must be a non-empty string'
            );
        }
        return $config['password'];
    }
}
