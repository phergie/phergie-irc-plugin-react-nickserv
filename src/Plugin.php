<?php
/**
 * Phergie (http://phergie.org)
 *
 * @link https://github.com/phergie/phergie-irc-plugin-react-nickserv for the canonical source repository
 * @copyright Copyright (c) 2008-2014 Phergie Development Team (http://phergie.org)
 * @license http://phergie.org/license Simplified BSD License
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
     * Identification command pattern.
     *
     * String patterns:
     * - %nickname% - bot's current nickname
     * - %password% - NickServ password
     *
     * @var string
     */
    protected $identifyCommand = 'IDENTIFY %nickname% %password%';

    /**
     * Regex pattern matching a request for identification
     *
     * @var string
     */
    protected $identifyPattern = '/This nickname is registered/';

    /**
     * Regex pattern matching a successful login notice
     *
     * @var string
     */
    protected $loginPattern = '/You are now identified/';

    /**
     * Regex pattern matching a ghosted nick notice
     *
     * @var string
     */
    protected $ghostPattern = '/has been ghosted/';

    /**
     * Name of the NickServ agent
     *
     * @var string
     */
    protected $botNick = 'NickServ';

    /**
     * Ghosted nick
     *
     * @var string|null
     */
    protected $ghostNick;

    /**
     * Whether to attempt ghosting
     *
     * @var bool
     */
    protected $ghostEnabled = false;

    /**
     * Accepts plugin configuration.
     *
     * Supported keys:
     *
     * password - required password used to authenticate the bot's identity to
     * the NickServ agent
     *
     * botnick - name of the NickServ service (optional)
     *
     * ghost - attempt to ghost the original nick if it is in use (optional)
     *   NOTE: you will need to use a plugin like AltNick to provide an alternative nickname
     *   during the registration phase, or the server will close your connection.
     *
     * identifypattern - custom regex pattern matching the text of the NOTICE received by NickServ
     * requesting identification (optional)
     *
     * loggedinpattern - custom regex pattern matching the text of the NOTICE received by NickServ
     * upon successful identification (optional)
     *
     * ghostpattern - custom regex pattern matching the text of the NOTICE received by NickServ
     * upon ghosting a nick (optional)
     *
     * @param array $config
     */
    public function __construct(array $config = array())
    {
        $this->password = $this->getConfigOption($config, 'password');
        if (isset($config['botnick'])) {
            $this->botNick = $this->getConfigOption($config, 'botnick');
        }
        if (!empty($config['ghost'])) {
            $this->ghostEnabled = true;
        }
        if (isset($config['identifycommand'])) {
            $this->identifyCommand = $this->getConfigOption($config, 'identifycommand');
        }
        if (isset($config['identifypattern'])) {
            $this->identifyPattern = $this->getConfigOption($config, 'identifypattern');
        }
        if (isset($config['loggedinpattern'])) {
            $this->loginPattern = $this->getConfigOption($config, 'loggedinpattern');
        }
        if (isset($config['ghostpattern'])) {
            $this->ghostPattern = $this->getConfigOption($config, 'ghostpattern');
        }
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
            'irc.received.nick' => 'handleNick',
            'irc.received.err_nicknameinuse' => 'handleNicknameInUse',
            'irc.received.rpl_endofmotd' => 'handleGhost',
            'irc.received.err_nomotd' => 'handleGhost',
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

        $connection = $event->getConnection();
        $params = $event->getParams();
        $message = $params['text'];

        // Authenticate the bot's identity for authentication requests
        if (preg_match($this->identifyPattern, $message)) {
            $command = str_replace(
                array('%nickname%', '%password%'),
                array($connection->getNickname(), $this->password),
                $this->identifyCommand
            );
            return $queue->ircPrivmsg($this->botNick, $command);
        }

        // Emit an event on successful authentication
        if (preg_match($this->loginPattern, $message)) {
            return $this->getEventEmitter()->emit('nickserv.identified', [$connection, $queue]);
        }

        // Reclaim primary nick on ghost confirmation
        if ($this->ghostNick !== null && preg_match($this->ghostPattern, $message)) {
            $queue->ircNick($this->ghostNick);
            $this->ghostNick = null;
            return;
        }
    }

    /**
     * Changes the nick associated with the bot in local memory when a change
     * to it is successfully registered with the server.
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
     * Kick-starts the ghost process.
     *
     * @param \Phergie\Irc\Event\ServerEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleNicknameInUse(ServerEvent $event, Queue $queue)
    {
        // Don't listen if ghost isn't enabled, or this isn't the first nickname-in-use error
        if (!$this->ghostEnabled || $this->ghostNick !== null) {
            return;
        }

        // Save the nick, so that we can send a ghost request once registration is complete
        $params = $event->getParams();
        $this->ghostNick = $params[1];
    }

    /**
     * Completes the ghost process.
     *
     * @param \Phergie\Irc\Event\ServerEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleGhost(ServerEvent $event, Queue $queue)
    {
        if ($this->ghostNick === null) {
            return;
        }

        // Attempt to kill the ghost connection
        $queue->ircPrivmsg($this->botNick, 'GHOST ' . $this->ghostNick . ' ' . $this->password);
    }

    /**
     * Extracts a string from the config options map.
     *
     * @param array $config
     * @param string $key
     * @return string
     * @throws \DomainException if password is unspecified or not a string
     */
    protected function getConfigOption(array $config, $key)
    {
        if (empty($config[$key]) || !is_string($config[$key])) {
            throw new \DomainException(
                "$key must be a non-empty string"
            );
        }
        return $config[$key];
    }
}
