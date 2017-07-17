<?php
/**
 * Inline Games - Telegram Bot (@inlinegamesbot)
 *
 * (c) 2017 Jack'lul <jacklulcat@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Commands\AdminCommands;

use Bot\Helper\Debug;
use Bot\Manager\Game;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Request;

/**
 * Class CleanCommand
 *
 * @package Longman\TelegramBot\Commands\AdminCommands
 */
class CleanCommand extends AdminCommand
{
    protected $name = 'clean';
    protected $description = 'Clean old game messages and set them as empty';
    protected $usage = '/clean';

    /**
     * @return \Longman\TelegramBot\Entities\ServerResponse
     */
    public function execute()
    {
        $message = $this->getMessage();
        $edited_message = $this->getUpdate()->getEditedMessage();

        if ($edited_message) {
            $message = $edited_message;
        }

        if ($message) {
            $chat_id = $message->getFrom()->getId();
            $text = trim($message->getText(true));

            $data = [];
            $data['chat_id'] = $chat_id;
        }

        $cleanInterval = $this->getConfig('clean_interval');

        if (isset($text) && is_numeric($text) && $text > 0) {
            $cleanInterval = $text;
        }

        if (empty($cleanInterval)) {
            $cleanInterval = 86400;  // 86400 seconds = 1 day
        }

        set_time_limit(90);

        $game = new Game('_', '_', $this);
        $storage = $game->getStorage();

        $inactive = [];
        if (class_exists($storage)) {
            $inactive = $storage::listFromStorage($cleanInterval);
        }

        $chat_action_start = 0;
        $last_request_time = 0;
        $timelimit = ini_get('max_execution_time') - 1;
        $start_time = time();

        $data['text'] = 'Executing... (time limit: ' . $timelimit . ' seconds)';
        Request::sendMessage($data);

        $cleaned = 0;
        $edited = 0;
        $error = 0;
        foreach ($inactive as $inactive_game) {
            if (time() >= $start_time + $timelimit) {
                Debug::log('Time limit reached!');
                break;
            }

            if ($chat_action_start < strtotime('-5 seconds')) {
                Request::sendChatAction(['chat_id' => $chat_id, 'action' => 'typing']);
                $chat_action_start = time();
            }

            Debug::log('Cleaning: ' . $inactive_game['id']);

            $game_data = $storage::selectFromStorage($inactive_game['id']);

            if (isset($game_data['game_code'])) {
                $game = new Game($inactive_game['id'], $game_data['game_code'], $this);

                if ($game->canRun()) {
                    while (time() <= $last_request_time + 10) {
                        Debug::log('Delaying next request');
                        sleep(1);
                    }

                    $result = Request::editMessageText(
                        [
                            'inline_message_id' => $inactive_game['id'],
                            'text' => '<b>' . $game->getGame()::getTitle() . '</b>' . PHP_EOL . PHP_EOL . '<i>' . __("This game session is empty.") . '</i>',
                            'reply_markup' => $this->createInlineKeyboard($game_data['game_code']),
                            'parse_mode' => 'HTML',
                            'disable_web_page_preview' => true,
                        ]
                    );

                    $last_request_time = time();

                    if ($result->isOk()) {
                        $edited++;
                        Debug::log('Message edited successfully');
                    } else {
                        $error++;
                        Debug::log('Failed to edit message: ' . $result->getDescription());
                    }
                }
            }

            if ($storage::deleteFromStorage($inactive_game['id'])) {
                $cleaned++;
                Debug::log('Removed from the database');
            }
        }

        $removed = 0;
        if (is_dir($dir = VAR_PATH . '/tmp')) {
            foreach (new \DirectoryIterator($dir) as $file) {
                if (!$file->isDir() && !$file->isDot() && $file->getMTime() < strtotime('-1 minute')) {
                    if (@unlink($dir . '/' . $file->getFilename())) {
                        $removed++;
                    }
                }
            }
        }

        if ($message) {
            $data['text'] = 'Cleaned ' . $cleaned . ' games, edited ' . $edited . ' messages, ' . $error . ' errored.' . PHP_EOL . 'Removed ' . $removed . ' temporary files.';

            return Request::sendMessage($data);
        }

        return Request::emptyResponse();
    }

    /**
     * Create inline keyboard with button that creates the game session
     *
     * @param $game_code
     *
     * @return InlineKeyboard
     */
    private function createInlineKeyboard($game_code)
    {
        $inline_keyboard = [
            [
                new InlineKeyboardButton(
                    [
                        'text' => __('Create'),
                        'callback_data' => $game_code . ';new'
                    ]
                )
            ]
        ];

        $inline_keyboard_markup = new InlineKeyboard(...$inline_keyboard);

        return $inline_keyboard_markup;
    }
}
