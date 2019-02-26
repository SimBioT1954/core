<?php
/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot;

defined('TB_BASE_PATH') || define('TB_BASE_PATH', __DIR__);
defined('TB_BASE_COMMANDS_PATH') || define('TB_BASE_COMMANDS_PATH', TB_BASE_PATH . '/Commands');

use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;

class Telegram
{
    /**
     * Version
     *
     * @var string
     */
    protected $version = '0.55.1';

    /**
     * Telegram API key
     *
     * @var string
     */
    protected $api_key = '';

    /**
     * Telegram Bot username
     *
     * @var string
     */
    protected $bot_username = '';

    /**
     * Telegram Bot id
     *
     * @var string
     */
    protected $bot_id = '';

    /**
     * Raw request data (json) for webhook methods
     *
     * @var string
     */
    protected $input;

    /**
     * Custom commands paths
     *
     * @var array
     */
    protected $commands_paths = [];

    /**
     * Current Update object
     *
     * @var \Longman\TelegramBot\Entities\Update
     */
    protected $update;

    /**
     * Upload path
     *
     * @var string
     */
    protected $upload_path;

    /**
     * Download path
     *
     * @var string
     */
    protected $download_path;

    /**
     * MySQL integration
     *
     * @var boolean
     */
    protected $mysql_enabled = false;

    /**
     * PDO object
     *
     * @var \PDO
     */
    protected $pdo;

    /**
     * Commands config
     *
     * @var array
     */
    protected $commands_config = [];

    /**
     * Admins list
     *
     * @var array
     */
    protected $admins_list = [];

    /**
     * ServerResponse of the last Command execution
     *
     * @var \Longman\TelegramBot\Entities\ServerResponse
     */
    protected $last_command_response;

    /**
     * Botan.io integration
     *
     * @var boolean
     */
    protected $botan_enabled = false;

    /**
     * Check if runCommands() is running in this session
     *
     * @var boolean
     */
    protected $run_commands = false;

    /**
     * Is running getUpdates without DB enabled
     *
     * @var bool
     */
    protected $getupdates_without_database = false;

    /**
     * Last update ID
     * Only used when running getUpdates without a database
     *
     * @var integer
     */
    protected $last_update_id;

    /**
     * Telegram constructor.
     *
     * @param string $api_key
     * @param string $bot_username
     *
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function __construct (string $api_key, $bot_username = '')
    {
        $this->bot_id = explode(':', $api_key, 2)[1];
        $this->api_key = $api_key;

        if (!empty($bot_username)) {
            $this->bot_username = $bot_username;
        }

        Request::initialize($this);
    }

    /**
     * Handle bot request from webhook
     *
     * @return bool
     *
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    /*
    public function handle ()
    {
        $this->input = Request::getInput();
        $post = json_decode($this->input, true);

        if ($response = $this->processUpdate(new Update($post, $this->bot_username))) {
            return $response->isOk();
        }

        return false;
    }*/

    /**
     * @param string $data
     *
     * @return Update
     * @throws TelegramException
     */
    public function getUpdate (string $data)
    {
        $post = json_decode($data, true);

        return new Update($post, $this->bot_username);

    }

    /**
     * Get the command name from the command type
     *
     * @param string $type
     *
     * @return string
     */
    protected function getCommandFromType ($type)
    {
        return $this->ucfirstUnicode(str_replace('_', '', $type));
    }

    /**
     * Process bot Update request
     *
     * @param \Longman\TelegramBot\Entities\Update $update
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function processUpdate (Update $update)
    {
        $this->update = $update;
        //  $this->last_update_id = $update->getUpdateId();

        //If all else fails, it's a generic message.
        $command = 'genericmessage';

        $update_type = $this->update->getUpdateType();
        if ($update_type === 'message') {
            $message = $this->update->getMessage();

            $type = $message->getType();
            if ($type === 'command') {
                $command = $message->getCommand();
            } elseif (in_array($type, [
                'new_chat_members',
                'left_chat_member',
                'new_chat_title',
                'new_chat_photo',
                'delete_chat_photo',
                'group_chat_created',
                'supergroup_chat_created',
                'channel_chat_created',
                'migrate_to_chat_id',
                'migrate_from_chat_id',
                'pinned_message',
                'invoice',
                'successful_payment',
            ], true)
            ) {
                $command = $this->getCommandFromType($type);
            }
        } else {
            $command = $this->getCommandFromType($update_type);
        }

        return $this->executeCommand($command);
    }

    /**
     * Execute /command
     *
     * @param string $command
     *
     * @return mixed
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function executeCommand ($command)
    {
        $command = strtolower($command);
        $command_obj = $this->getCommandObject($command);

        if (!$command_obj || !$command_obj->isEnabled()) {
            //Failsafe in case the Generic command can't be found
            if ($command === 'generic') {
                throw new TelegramException('Generic command missing!');
            }

            //Handle a generic command or non existing one
            $this->last_command_response = $this->executeCommand('generic');
        } else {

            //execute() method is executed after preExecute()
            //This is to prevent executing a DB query without a valid connection
            $this->last_command_response = $command_obj->preExecute();
        }

        return $this->last_command_response;
    }

    /**
     * Sanitize Command
     *
     * @param string $command
     *
     * @return string
     */
    protected function sanitizeCommand ($command)
    {
        return str_replace(' ', '', $this->ucwordsUnicode(str_replace('_', ' ', $command)));
    }

    /**
     * Enable a single Admin account
     *
     * @param integer $admin_id Single admin id
     *
     * @return \Longman\TelegramBot\Telegram
     */
    public function enableAdmin ($admin_id)
    {
        if (!is_int($admin_id) || $admin_id <= 0) {
            TelegramLog::error('Invalid value "%s" for admin.', $admin_id);
        } elseif (!in_array($admin_id, $this->admins_list, true)) {
            $this->admins_list[] = $admin_id;
        }

        return $this;
    }

    /**
     * Enable a list of Admin Accounts
     *
     * @param array $admin_ids List of admin ids
     *
     * @return \Longman\TelegramBot\Telegram
     */
    public function enableAdmins (array $admin_ids)
    {
        foreach ($admin_ids as $admin_id) {
            $this->enableAdmin($admin_id);
        }

        return $this;
    }

    /**
     * Get list of admins
     *
     * @return array
     */
    public function getAdminList ()
    {
        return $this->admins_list;
    }

    /**
     * Check if the passed user is an admin
     *
     * If no user id is passed, the current update is checked for a valid message sender.
     *
     * @param int|null $user_id
     *
     * @return bool
     */
    public function isAdmin ($user_id = null)
    {
        if ($user_id === null && $this->update !== null) {
            //Try to figure out if the user is an admin
            $update_methods = [
                'getMessage',
                'getEditedMessage',
                'getChannelPost',
                'getEditedChannelPost',
                'getInlineQuery',
                'getChosenInlineResult',
                'getCallbackQuery',
            ];
            foreach ($update_methods as $update_method) {
                $object = call_user_func([$this->update, $update_method]);
                if ($object !== null && $from = $object->getFrom()) {
                    $user_id = $from->getId();
                    break;
                }
            }
        }

        return ($user_id === null) ? false : in_array($user_id, $this->admins_list, true);
    }

    /**
     * Add a single custom commands path
     *
     * @param string $path Custom commands path to add
     * @param bool   $before If the path should be prepended or appended to the list
     *
     * @return \Longman\TelegramBot\Telegram
     */
    public function addCommandsPath ($path, $before = true)
    {
        if (!is_dir($path)) {
            TelegramLog::error('Commands path "%s" does not exist.', $path);
        } elseif (!in_array($path, $this->commands_paths, true)) {
            if ($before) {
                array_unshift($this->commands_paths, $path);
            } else {
                $this->commands_paths[] = $path;
            }
        }

        return $this;
    }

    /**
     * Add multiple custom commands paths
     *
     * @param array $paths Custom commands paths to add
     * @param bool  $before If the paths should be prepended or appended to the list
     *
     * @return \Longman\TelegramBot\Telegram
     */
    public function addCommandsPaths (array $paths, $before = true)
    {
        foreach ($paths as $path) {
            $this->addCommandsPath($path, $before);
        }

        return $this;
    }

    /**
     * Return the list of commands paths
     *
     * @return array
     */
    public function getCommandsPaths ()
    {
        return $this->commands_paths;
    }

    /**
     * Set custom upload path
     *
     * @param string $path Custom upload path
     *
     * @return \Longman\TelegramBot\Telegram
     */
    public function setUploadPath ($path)
    {
        $this->upload_path = $path;

        return $this;
    }

    /**
     * Get custom upload path
     *
     * @return string
     */
    public function getUploadPath ()
    {
        return $this->upload_path;
    }

    /**
     * Set custom download path
     *
     * @param string $path Custom download path
     *
     * @return \Longman\TelegramBot\Telegram
     */
    public function setDownloadPath ($path)
    {
        $this->download_path = $path;

        return $this;
    }

    /**
     * Get custom download path
     *
     * @return string
     */
    public function getDownloadPath ()
    {
        return $this->download_path;
    }

    /**
     * Set command config
     *
     * Provide further variables to a particular commands.
     * For example you can add the channel name at the command /sendtochannel
     * Or you can add the api key for external service.
     *
     * @param string $command
     * @param array  $config
     *
     * @return \Longman\TelegramBot\Telegram
     */
    public function setCommandConfig ($command, array $config)
    {
        $this->commands_config[$command] = $config;

        return $this;
    }

    /**
     * Get command config
     *
     * @param string $command
     *
     * @return array
     */
    public function getCommandConfig ($command)
    {
        return $this->commands_config[$command] ?? [];
    }

    /**
     * Get API key
     *
     * @return string
     */
    public function getApiKey ()
    {
        return $this->api_key;
    }

    /**
     * Get Bot name
     *
     * @return string
     */
    public function getBotUsername ()
    {
        return $this->bot_username;
    }

    /**
     * Get Bot Id
     *
     * @return string
     */
    public function getBotId ()
    {
        return $this->bot_id;
    }

    /**
     * Get Version
     *
     * @return string
     */
    public function getVersion ()
    {
        return $this->version;
    }

    /**
     * Set Webhook for bot
     *
     * @param string $url
     * @param array  $data Optional parameters.
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function setWebhook ($url, array $data = [])
    {
        $data = array_intersect_key($data, array_flip([
            'certificate',
            'max_connections',
            'allowed_updates',
        ]));
        $data['url'] = $url;

        // If the certificate is passed as a path, encode and add the file to the data array.
        if (!empty($data['certificate']) && is_string($data['certificate'])) {
            $data['certificate'] = Request::encodeFile($data['certificate']);
        }

        $result = Request::setWebhook($data);

        if (!$result->isOk()) {
            throw new TelegramException(
                'Webhook was not set! Error: ' . $result->getErrorCode() . ' ' . $result->getDescription()
            );
        }

        return $result;
    }

    /**
     * Delete any assigned webhook
     *
     * @return mixed
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function deleteWebhook ()
    {
        $result = Request::deleteWebhook();

        if (!$result->isOk()) {
            throw new TelegramException(
                'Webhook was not deleted! Error: ' . $result->getErrorCode() . ' ' . $result->getDescription()
            );
        }

        return $result;
    }

    /**
     * Replace function `ucwords` for UTF-8 characters in the class definition and commands
     *
     * @param string $str
     * @param string $encoding (default = 'UTF-8')
     *
     * @return string
     */
    protected function ucwordsUnicode ($str, $encoding = 'UTF-8')
    {
        return mb_convert_case($str, MB_CASE_TITLE, $encoding);
    }

    /**
     * Replace function `ucfirst` for UTF-8 characters in the class definition and commands
     *
     * @param string $str
     * @param string $encoding (default = 'UTF-8')
     *
     * @return string
     */
    protected function ucfirstUnicode ($str, $encoding = 'UTF-8')
    {
        return mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding)
            . mb_strtolower(mb_substr($str, 1, mb_strlen($str), $encoding), $encoding);
    }

    /**
     * Switch to enable running getUpdates without a database
     *
     * @param bool $enable
     */
    public function useGetUpdatesWithoutDatabase ($enable = true)
    {
        $this->getupdates_without_database = $enable;
    }

    /**
     * Return last update id
     *
     * @return int
     */
    public function getLastUpdateId ()
    {
        return $this->last_update_id;
    }
}
