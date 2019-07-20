<?php
namespace App\Babel\Extension\uva;

use App\Babel\Submit\Curl;
use App\Models\SubmissionModel;
use App\Models\JudgerModel;
use App\Models\OJModel;
use Requests;
use Exception;
use Log;

class Judger extends Curl
{

    public $verdict = [
        10 => 'Submission Error',
        15 => 'Submission Error', // Can't be judged
        // 20 In queue
        30 => "Compile Error",
        35 => "Compile Error", // Restricted function
        40 => "Runtime Error",
        45 => "Output Limit Exceeded",
        50 => "Time Limit Exceed",
        60 => "Memory Limit Exceed",
        70 => "Wrong Answer",
        80 => "Presentation Error",
        90 => "Accepted",
    ];
    private $list = [];


    public function __construct()
    {
        $this->submissionModel = new SubmissionModel();
        $this->judgerModel = new JudgerModel();

        $this->list = [];
        $earliest = $this->submissionModel->getEarliestSubmission(OJModel::oid('uva'));
        if (!$earliest) return;

        $judgerDetail = $this->judgerModel->detail($earliest['jid']);
        $this->handle = $judgerDetail['handle'];

        $response = $this->grab_page([
            'site' => "https://uhunt.onlinejudge.org/api/subs-user/" . $judgerDetail['user_id'] . "/" . ($earliest['remote_id'] - 1),
            'oj' => 'uva',
            'handle' => $judgerDetail['handle'],
        ]);
        $result = json_decode($response, true);
        foreach ($result['subs'] as $i) {
            $this->list[$i[0]] = ['time' => $i[3], 'verdict' => $i[2]];
        }
    }

    public function judge($row)
    {
        if (array_key_exists($row['remote_id'], $this->list)) {
            $sub = [];
            if (!isset($this->verdict[$this->list[$row['remote_id']]['verdict']])) { // Sometimes verdict is 0 and i have no idea why
                return;
            }
            $sub['verdict'] = $this->verdict[$this->list[$row['remote_id']]['verdict']];
            if ($sub['verdict'] === 'Compile Error') {
                $response = $this->grab_page([
                    'site' => "https://uva.onlinejudge.org/index.php?option=com_onlinejudge&Itemid=9&page=show_compilationerror&submission=$row[remote_id]",
                    'oj' => 'uva',
                    'handle' => $this->handle,
                ]);
                if (preg_match('/<pre>([\s\S]*)<\/pre>/', $response, $match)) {
                    $sub['compile_info'] = trim($match[1]);
                }
            }
            $sub['score'] = $sub['verdict'] == "Accepted" ? 1 : 0;
            $sub['remote_id'] = $row['remote_id'];
            $sub['time'] = $this->list[$row['remote_id']]['time'];

            // $ret[$row['sid']]=[
            //     "verdict"=>$sub['verdict']
            // ];
            $this->submissionModel->updateSubmission($row['sid'], $sub);
        }
    }
}
