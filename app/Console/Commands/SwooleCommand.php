<?php

namespace App\Console\Commands;

use App\Message;
use App\Room;
use App\RoomJoin;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class SwooleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravel:swoole {action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected $serv;

    /**
     * @var Message
     */
    protected $message;

    /**
     * @var User
     */
    protected $user;

    /**
     * @var Room
     */
    protected $room;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    // public function __construct()
    // {
    //     parent::__construct();
    // }

    /**
     * Swoole constructor.
     * @param Message $message
     * @param User $user
     * @param RoomJoin $room
     */
    public function __construct(Message $message, User $user, RoomJoin $room)
    {
        parent::__construct();
        $this->message = $message;
        $this->user = $user;
        $this->room = $room;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $arg = $this->argument('action');

        switch ($arg) {
            case 'start':
                $this->info('swoole observer started');
                $this->start();
                break;

            case 'stop':
                $this->info('stoped');
                break;
            // 1.执行 ps -aux|grep artisan命令，获取pid（有多个进程，杀第一个即可）
            // 2.执行 kill pid命令，pid是第一步你获取的
            // 3.如果想后台值守，一定加上nohup命令！！！

            case 'restart':
                $this->info('restarted');
                break;

            default:
                # code...
                break;
        }
    }

    /**
     * [start description]
     * @return [type] [description]
     */
    private function start()
    {
        /**
         * websocket
         */
        $ws = new \swoole_websocket_server(config('swoole.host'), config('swoole.port'));
        $ws->on('open', function ($ws, $request) {
            // todo something
        });

        //监听WebSocket消息事件
        $ws->on('message', function ($ws, $frame) {
            $data = json_decode($frame->data, true);
            switch ($data['type']) {
                case 'connect':
                    Redis::zadd("room:{$data['room_id']}", intval($data['user_id']), $frame->fd);
                   // 同时使用hash标识fd在哪个房间
                    Redis::hset('room', $frame->fd, $data['room_id']);
                   // 加入房间提示
                   // 获取这个房间的用户总数
                   // +1 是代表群主
                    $memberInfo = [
                        'online' => Redis::zcard("room:{$data['room_id']}"),
                        'all' => $this->room->where(['room_id' => $data['room_id'], 'status' => 0])->count() + 1
                    ];
                    $this->sendAll($ws, $data['room_id'], $data['user_id'], $memberInfo,
                        'join');
                    break;
                case 'message':
                   // 入库
                    $message = [
                        'content' => $data['message'],
                        'user_id' => $data['user_id'],
                        'room_id' => $data['room_id'],
                        'created_at' => time()
                    ];
                   // $this->message->fill($message)->save();
                    Message::create($message);
                    $this->sendAll($ws, $data['room_id'], $data['user_id'], $data['message']);
                    break;
                case 'close':
                   // 移除
                    Redis::zrem("room:{$data['room_id']}", $frame->fd);

                    break;
            }

        });

        $ws->on('close', function ($ws, $fd) {
           // 获取fd所对应的房间号
            $room_id = Redis::hget('room', $fd);
            $user_id = intval(Redis::zscore("room:{$room_id}", $fd));
            Redis::zrem("room:{$room_id}", $fd);
            $memberInfo = [
                'online' => Redis::zcard("room:{$room_id}"),
                'all' => $this->room->where(['room_id' => $room_id, 'status' => 0])->count() + 1
            ];
            $this->sendAll($ws, $room_id, $user_id, $memberInfo,
                'leave');
        });

        $ws->start();

        /**
         * tcp
         */
        // $this->serv = new swoole_server('0.0.0.0', 9501);
        // $this->serv->set([
        //     'worker_num' => 8,
        //     'daemonize' => false,
        //     'max_request' => 10000,
        //     'dispatch_mode' => 2,
        //     'debug_mode' => 1
        // ]);
        // $handler = App::make('handlers\SwooleHandler');
        // $this->serv->on('Start', [$handler, 'onStart']);
        // $this->serv->on('Connect', [$handler, 'onConnect']);
        // $this->serv->on('Receive', [$handler, 'onReceive']);
        // $this->serv->on('Close', [$handler, 'onClose']);

        // $this->serv->start();
    }

    /**
     * @param $ws
     * @param $room_id
     * @param string $user_id
     * @param string $message
     * @param string $type
     * @return bool
     */
    private function sendAll($ws, $room_id, $user_id = null, $message = null, $type = 'message')
    {
        $user = $this->user->find($user_id, ['id', 'name']);
        if (!$user) {
            return false;
        }
        $message = json_encode([
            'message' => is_string($message) ? nl2br($message) : $message,
            'type' => $type,
            'user' => $user
        ]);
        $members = Redis::zrange("room:{$room_id}" , 0 , -1);
        foreach ($members as $fd) {
            $ws->push($fd, $message);
        }
    }
}
