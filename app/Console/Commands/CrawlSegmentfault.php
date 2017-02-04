<?php

namespace App\Console\Commands;

use App\Article;
use App\Comment;
use App\Service\UserService;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class CrawlSegmentfault extends Command
{
    use CommandHelper;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'CrawlSegmentfault {url?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if ($this->argument('url')){
            $question = $this->getQuestionPage($this->argument('url'));
            $this->storeQuestion($question);
            return ;
        }
        if (\Storage::exists('index_list.diff')) {
            $oldIndexList = \GuzzleHttp\json_decode(\Storage::get('index_list.diff'),true);
        } else {
            $oldIndexList = [];
        }

        $body = $this->request('https://segmentfault.com/questions');
        $dom = new Crawler($body);

        $dom->filter('.stream-list .stream-list__item')->each(function (Crawler $node, $i) use (&$new_index_list) {
            $url = $node->filter('.title a')->attr('href');
            $answersCount = intval($node->filter('.qa-rank .answers')->html());
            $solved = $node->filter('.qa-rank .solved')->count();
            $md5 = "$url-$answersCount-$solved";
            $new_index_list[$md5] = $url;
        });



        $index_list = array_diff_key($new_index_list, $oldIndexList);
        foreach ($index_list as $k => $questionPageUrl) {
            \Log::info('diff: ' . $k . ':' . $questionPageUrl);
            $question = $this->getQuestionPage('https://segmentfault.com' . $questionPageUrl);
            $this->storeQuestion($question);
        }
        if ($index_list) {
            $diffJson = \GuzzleHttp\json_encode($new_index_list, JSON_PRETTY_PRINT);
            \Storage::put('index_list.diff', $diffJson);
        }
    }

    public function storeQuestion($question)
    {

        $user = UserService::firstOrCreate(['email' => $question['user']['email']], $question['user']);
        if($user->wasRecentlyCreated){
            \Log::info('add question user: ' . $user->email);
        }
        unset($question['user']);
        $question['user_id'] = $user->id;

        $article = Article::firstOrCreate(Arr::only($question,['slug']), $question);
        if($article->wasRecentlyCreated){
            \Log::info('add question: ' . $article->slug);
        }
        foreach ($question['answers'] as $answer) {


            $answerUser = UserService::firstOrCreate(Arr::only($answer['user'],['email']), $answer['user']);
            if($answerUser->wasRecentlyCreated){
                \Log::info('add answer user: ' . $answerUser->email);
            }

            unset($answer['user']);
            $answer['user_id'] = $answerUser->id;
            $answer['article_id'] = $article->id;
            $comment = Comment::firstOrCreate(Arr::only($answer,['slug']), $answer);
            if($answer['is_awesome'] != $comment->is_awesome){
                $comment->is_awesome = $answer['is_awesome'];
                $comment->save();
                \Log::info('answer change awesome: ' . $comment->slug);
            }
            if($comment->wasRecentlyCreated){
                \Log::info('add answer: ' . $comment->slug);
            }

        }
    }

    public function getQuestionPage($questionPageUrl)
    {
        $body = $this->request($questionPageUrl);
        $dom = new Crawler($body);

        $question['url'] = $questionPageUrl;
        $question['slug'] = 'segmentfault-'.$dom->filter('#questionTitle')->attr('data-id');
        $question['title'] = utf8_to_unicode_str($dom->filter('#questionTitle>a')->text());
        $question['body'] = utf8_to_unicode_str(trim($dom->filter('.question')->html()));



        $userName = $dom->filter('.question__author a strong')->first()->text();
        $userUrl = $dom->filter('.question__author a')->first()->attr('href');

        $u = explode('/', $userUrl);
        $user_id = end($u);;
        $userEmail = $user_id . '@segmentfault.com';

        $question['user'] = [
            'name' => utf8_to_unicode_str($userName),
            'email' => $userEmail,
            'password' => Str::random(),
        ];

        $question['answers'] = $dom->filter('.widget-answers__item[id]')->each(function (Crawler $node, $i) {
            $userName = $node->filter('.answer__info--author-name')->first()->text();
            $userUrl = $node->filter('.answer__info--author-name')->first()->attr('href');

            $u = explode('/', $userUrl);
            $user_id = end($u);
            $userEmail = $user_id . '@segmentfault.com';

            return [
                'slug' => 'segmentfault-'.$node->attr('id'),
                'time' => $node->filter('.list-inline>li')->first()->filter('a')->text(),
                'body' => utf8_to_unicode_str(trim($node->filter('.answer')->first()->html())),
                'is_awesome' => $node->filter('.accepted-check-icon')->count(),
                'user' => [
                    'name' => utf8_to_unicode_str($userName),
                    'email' => $userEmail,
                    'password' => Str::random(),
                    'rank' => $node->filter('.answer__info--author-rank')->first()->text(),
                ],
            ];
        });
        return $question;
    }
}