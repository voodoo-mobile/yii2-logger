<?php
/**
 * @copyright Copyright (c) 2013-2016 Voodoo Mobile Consulting Group LLC
 * @link      https://voodoo.rocks
 * @license   http://opensource.org/licenses/MIT The MIT License (MIT)
 */
namespace vm\logger;

use app\models\ext\User;
use Monolog\Handler\RedisHandler;
use Monolog\Handler\SlackHandler;
use Monolog\Logger;
use Predis\Client;
use Yii;
use yii\base\Exception;
use yii\log\Target;
use yii\web\HttpException;

/**
 * Class MonologTarget
 * Usage
 * ```php
 * [
 *     'components' => [
 *         'log' => [
 *             'targets' => [
 *              [
 *                 'class'         => 'vm\logger\MonologTarget',
 *                 'slackApiToken' => 'SLACK_API_TOKEN',
 *                 'slackChannel'  => '#crashes',
 *                 'redisDsn'      => 'tcp://example.com:6379',
 *                 'redisAuth'     => 'REDIS_AUTH_PASSWORD',
 *                 'levels'        => ['error', 'warning'],
 *                 'except'        => [
 *                     'yii\web\HttpException:404',
 *                     'yii\web\HttpException:403',
 *                     ],
 *                 ],
 *             ],
 *         ],
 *     ],
 * ]
 * ```
 * @package app\components
 */
class MonologTarget extends Target
{
    /**
     * @var string
     */
    public $slackApiToken = null;

    /**
     * @var string
     */
    public $slackChannel = '#crashes';

    /**
     * @var string
     */
    public $redisDsn = null;

    /**
     * @var string
     */
    public $redisAuth = null;

    /**
     * @var bool
     */
    public $slackEnabled = true;

    /**
     * @var bool
     */
    public $redisEnabled = true;

    /**
     * @var string the view file to be rendered. If not set, it will take the value of [[id]].
     * That means, if you name the action as "error" in "SiteController", then the view name
     * would be "error", and the corresponding view file would be "views/site/error.php".
     */
    public $view;

    /**
     * @var string the name of the error when the exception name cannot be determined.
     * Defaults to "Error".
     */
    public $defaultName;

    /**
     * @var string the message to be displayed when the exception message contains sensitive information.
     * Defaults to "An internal server error occurred.".
     */
    public $defaultMessage;

    /**
     *
     */
    public function export()
    {
        if (($exception = Yii::$app->getErrorHandler()->exception) === null) {
            return false;
        }

        if ($exception instanceof HttpException) {
            $code = $exception->statusCode;
        } else {
            $code = null;
        }
        if ($exception instanceof Exception) {
            $name = $exception->getName();
        } else {
            $name = $this->defaultName ?: Yii::t('yii', 'Error');
        }
        if ($code) {
            $name .= " (#$code)";
        }

        $message = $exception->getMessage();
        $user    = null;

        ob_start();
        echo '```';
        print_r($_GET);
        echo '```';
        $get = ob_get_contents();
        ob_end_clean();

        ob_start();
        echo '```';
        print_r($_POST);
        echo '```';
        $post = ob_get_contents();
        ob_end_clean();

        if (!Yii::$app->user->isGuest && method_exists('User', 'loggedIn')) {
            ob_start();
            echo '```';
            print_r(User::loggedIn()->attributes);
            echo '```';
            $user = ob_get_contents();
            ob_end_clean();
        }

        ob_start();
        echo '*_', Yii::$app->id, '_*', PHP_EOL;
        echo '*', $name, ' - ', $message, '*', PHP_EOL;
        echo '`', $exception->getFile(), " : ", $exception->getLine(), '`', PHP_EOL, PHP_EOL;

        echo $_GET ? '*GET:*' . PHP_EOL . $get . PHP_EOL . PHP_EOL : '';
        echo $_POST ? '*POST:*' . PHP_EOL . $post . PHP_EOL . PHP_EOL : '';

        echo isset($_SERVER['REQUEST_URI'])
            ? '*REQUEST URI:*' . PHP_EOL . '```' . $_SERVER['REQUEST_URI'] . '```' . PHP_EOL . PHP_EOL
            : '';
        echo isset($_SERVER['HTTP_REFERER'])
            ? '*REFERRER:*' . PHP_EOL . '```' . $_SERVER['HTTP_REFERER'] . '```' . PHP_EOL . PHP_EOL
            : '';
        echo isset($_SERVER['HTTP_USER_AGENT'])
            ? '*USER AGENT:*' . PHP_EOL . '```' . $_SERVER['HTTP_USER_AGENT'] . '```' . PHP_EOL . PHP_EOL
            : '';

        echo '*IP:*', PHP_EOL, '```', $_SERVER['REMOTE_ADDR'], '```', PHP_EOL, PHP_EOL;

        echo Yii::$app->user->isGuest ? '*Guest user*' . PHP_EOL . PHP_EOL : '';
        echo $user ? '*User:*' . PHP_EOL . $user . PHP_EOL . PHP_EOL : '';

        echo '*Stacktrace:*', PHP_EOL, '```', $exception->getTraceAsString(), '```', PHP_EOL, PHP_EOL;
        $text = ob_get_contents();
        ob_end_clean();

        $logger = new Logger(Yii::$app->id);

        if ($this->redisEnabled) {
            $redis = new Client($this->redisDsn);
            $redis->auth($this->redisAuth);
            $logger->pushHandler(new RedisHandler($redis, Yii::$app->id, Logger::WARNING));
        }

        if ($this->slackEnabled) {
            $logger->pushHandler(new SlackHandler(
                    $this->slackApiToken,
                    $this->slackChannel,
                    isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : Yii::$app->id,
                    false,
                    ':smiling_imp:',
                    Logger::WARNING
                )
            );
        }
        $logger->addError($text);
    }
}