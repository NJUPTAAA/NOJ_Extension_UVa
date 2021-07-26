<?php
namespace App\Babel\Extension\uva;

use App\Babel\Crawl\CrawlerBase;
use App\Models\ProblemModel;
use App\Models\OJModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;
use Storage;

class Crawler extends CrawlerBase
{
    public $oid = null;
    private $con;
    private $imgi;
    /**
     * Initial
     *
     * @return Response
     */
    public function start($conf)
    {
        $action = isset($conf["action"]) ? $conf["action"] : 'crawl_problem';
        $con = isset($conf["con"]) ? $conf["con"] : 'all';
        $cached = isset($conf["cached"]) ? $conf["cached"] : false;
        $this->oid = OJModel::oid('uva');

        if (is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }

        $this->problemModel = new ProblemModel();

        if ($action == 'judge_level') {
            $this->judge_level();
        } else {
            $this->crawl($con, $action == 'update_problem', $cached);
        }
    }

    public function judge_level()
    {
        // TODO
    }

    public function crawl($con, $incremental, $cached)
    {
        if ($cached) {
            $res = file_get_contents(__DIR__ . '/problemset.problems');
        } else {
            $res = Requests::get("https://uhunt.onlinejudge.org/api/p", [], ['timeout' => 600]);
            file_put_contents(__DIR__ . '/problemset.problems', $res = $res->body);
        }
        $result = json_decode($res, true);
        $this->updmsg = $incremental ? 'Updating' : 'Crawling';
        $this->donemsg = $incremental ? 'Updated' : 'Crawled';
        $failures = [];
        if ($con == 'all') {
            $info = [];
            for ($i = 0; $i < count($result); ++$i) {
                $info[$result[$i][1]] = [$result[$i][0], $result[$i][2], $result[$i][3], $result[$i][19]];
            }
            ksort($info);
            foreach ($info as $key => $value) {
                if ($incremental && !empty($this->problemModel->basic($this->problemModel->pid('UVa' . $key)))) {
                    continue;
                }
                if (!$this->_crawl($key, $value)) {
                    array_push($failures, $key);
                }
            }
        } else {
            for ($i = 0; $i < count($result); ++$i) {
                if ($result[$i][1] == $con) {
                    if (!$this->_crawl($con, [$result[$i][0], $result[$i][2], $result[$i][3], $result[$i][19]])) {
                        $failures = [$result[$i][1]];
                    }
                    break;
                }
            }
        }

        if ($failures) {
            $resultmsg = "\n  <bg=red;fg=white> Failed problems </> : ";
            $len = 22;
            $last = 'UVa' . $failures[count($failures) - 1];
            foreach ($failures as $id) {
                $id = 'UVa' . $id;
                if ($id != $last) {
                    $id = $id . ', ';
                }
                if ($len + strlen($id) >= 120) {
                    $resultmsg .= "\n                      ";
                    $len = 22;
                }
                $resultmsg .= $id;
                $len += strlen($id);
            }
        } else {
            $resultmsg = "\n  <fg=green>{$this->donemsg} successful.</>";
        }
        $this->line($resultmsg);
    }

    private function _crawl($con, $info)
    {
        $updmsg = $this->updmsg;
        $donemsg = $this->donemsg;
        $this->line("<fg=yellow>{$updmsg}:   </>UVa$con");

        try {
            $pf = substr($con, 0, strlen($con) - 2);
            $res = Requests::get("https://onlinejudge.org/external/$pf/p$con.pdf");
            if (!$res->success) {
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Problem's PDF not found.</>\n");
                return;
            }
            Storage::disk('NOJPublic')->makeDirectory("external/gym/");
            file_put_contents(base_path("public/external/gym/UVa$con.pdf"), $res->body);
        } catch (Exception $e) {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Failed while downloading PDF.</>\n");
            return;
        }

        $this->pro['pcode'] = 'UVa' . $con;
        $this->pro['OJ'] = $this->oid;
        $this->pro['contest_id'] = null;
        $this->pro['index_id'] = $info[0];
        $this->pro['origin'] = "https://onlinejudge.org/index.php?option=com_onlinejudge&Itemid=8&page=show_problem&problem=" . $info[0];
        $this->pro['title'] = $info[1];
        $this->pro['time_limit'] = $info[3];
        $this->pro['memory_limit'] = 131072; // Given in elder codes
        $this->pro['solved_count'] = $info[2];
        $this->pro['input_type'] = 'standard input';
        $this->pro['output_type'] = 'standard output';
        $this->pro['description'] = '';
        $this->pro['input'] = '';
        $this->pro['output'] = '';
        $this->pro['note'] = '';
        $this->pro['sample'] = [];
        $this->pro['source'] = 'Here';
        $this->pro['file'] = 1;
        $this->pro['file_url'] = "/external/gym/UVa{$con}.pdf";

        $problem = $this->problemModel->pid($this->pro['pcode']);

        if ($problem) {
            $this->problemModel->clearTags($problem);
            $new_pid = $this->updateProblem($this->oid);
        } else {
            $new_pid = $this->insertProblem($this->oid);
        }

        // $this->problemModel->addTags($new_pid, $tag); // not present

        $this->line("<fg=green>$donemsg:    </>UVa$con");
        return true;
    }
}
