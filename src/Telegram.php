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
        $this->bot_username = $bot_username;
    }

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
}
