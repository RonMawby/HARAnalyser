<?php

/**
 * Description of harParse
 *
 * @author MawbyR
 */
class harParse {

    const SESS = 'CREATE TABLE SESSION ( ID INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, SESSION TEXT, DATE INTEGER);';
    const REQUEST = 'CREATE TABLE REQUEST (KEYID INTEGER PRIMARY KEY AUTOINCREMENT, SESSID INTEGER, REQID INTEGER, STIME TEXT,
           TIME FLOAT, METHOD TEXT,TYPE TEXT, URL TEXT, STATUS INTEGER,SIZE INTEGER);';
    const IMAGETYPE = '|image/cgm|image/fits|image/g3fax|image/gif|image/ief|image/jp2|image/jpeg|image/jpm|image/jpx|image/naplps|image/png|image/prs.btif
|image/prs.pti|image/t38|image/tiff|image/tiff-fx|image/vnd.adobe.photoshop|image/vnd.cns.inf2|image/vnd.djvu
|image/vnd.dwg|image/vnd.dxf|image/vnd.fastbidsheet|image/vnd.fpx|image/vnd.fst
|image/vnd.fujixerox.edmics-mmr|image/vnd.fujixerox.edmics-rlc|image/vnd.globalgraphics.pgb
|image/vnd.microsoft.icon|image/vnd.mix|image/vnd.ms-modi|image/vnd.net-fpx
|image/vnd.sealed.png|image/vnd.sealedmedia.softseal.gif|image/vnd.sealedmedia.softseal.jpg
|image/vnd.svf|image/vnd.wap.wbmp|image/vnd.xiff|';
    const FONTTYPE = '|font/otf|font/sfnt|font/ttf|font/woff|font/woff2|';

    protected $oPDO;
    protected $index;
    protected $size;
    protected $totalSize = 0;

    public function __construct() {
        session_start();
        $this->oPDO = new PDO('sqlite:/tmp/har.db3');
        foreach (array('SESS', 'REQUEST') as $v) {
            $this->{"createTable{$v}"}();
        }
    }

    public function parseEntries(&$entries) {
        $this->init();
        $size = count($entries);
        $this->clearRequest();
        try {
            for ($i = 0; $i < $size; $i++) {
                $entry = $entries[$i];

                $stmt = $this->oPDO->prepare('INSERT INTO REQUEST (SESSID,REQID,STIME,TIME
                ,METHOD,TYPE,URL,STATUS,SIZE) VALUES (:id,:reqid,:stime,:time,:method,:type,:url,:status,:size);');

                $stmt->bindValue(':id', $this->index, PDO::PARAM_INT);
                $stmt->bindValue(':reqid', ($i + 1), PDO::PARAM_INT);
                $stmt->bindValue(':stime', $entry->startedDateTime, PDO::PARAM_STR);
                $stmt->bindValue(':time', $entry->time, PDO::PARAM_STR);
                $stmt->bindValue(':method', $entry->request->method, PDO::PARAM_STR);
                $stmt->bindValue(':type', $entry->response->content->mimeType, PDO::PARAM_STR);
                $stmt->bindValue(':status', $entry->response->status, PDO::PARAM_STR);
                $stmt->bindValue(':url', $entry->request->url, PDO::PARAM_STR);
                $stmt->bindValue(':size', $entry->response->content->size, PDO::PARAM_INT);
                $stmt->execute();
                $stmt->closeCursor();
            }
        } catch (Exception $e) {
            $this->clearRequest();
        }
        return $this->getCurrentRequest();
    }

    public function getCurrentRequest() {
        $stmt = $this->oPDO->prepare('SELECT * FROM REQUEST WHERE SESSID=' . $this->index . ';');
        $stmt->execute();
        $data = $stmt->fetchAll();
        $oResult = $this->formatData($data);
        $stmt->closeCursor();
        return $oResult;
    }

    public function getRequestSizeByType() {
        $oSize = new stdClass();
        $oSize->total = $this->calculateSize($this->totalSize);
        $html = $image = $jscript = $css = $font = $other = 0;
        foreach ($this->size as $type => $value) {
            switch ($type) {
                case 'text/html':
                    $html += $value;
                    break;
                case 'text/css':
                    $css += $value;
                    break;
                case 'application/javascript':
                case 'application/x-javascript':
                case 'text/javascript':
                    $jscript += $value;
                    break;
                default:
                    if (strpos(self::IMAGETYPE, '|' . $type . '|') !== false) {
                        $image += $value;
                    } elseif (strpos(self::FONTTYPE, '|' . $type . '|') !== false) {
                        $font += $value;
                    } else {
                        $other += $value;
                    }
            }
        }
        $oSize->image = $this->calculateSize($image);
        $oSize->htmlText = $this->calculateSize($html);
        $oSize->jscript = $this->calculateSize($jscript);
        $oSize->css = $this->calculateSize($css);
        $oSize->font = $this->calculateSize($font);
        $oSize->other = $this->calculateSize($other);
        return $oSize;
    }

    public function sortData($jsonb64) {
        try {
            $jsonString = base64_decode($jsonb64);
            $oData = json_decode($jsonString);
            switch ($oData->sortBy) {
                case 2: //URL
                    $order = 'URL';
                    break;
                case 3://Load Time
                    $order = 'TIME';
                    break;
                case 4: //Size
                    $order = 'SIZE';
                    break;
                default:
                    $order = 'REQID';
            }
            if($oData->sortOrder ==0) {
                $order .=' DESC';
            }
            $this->init();
            $stmt = $this->oPDO->prepare('SELECT * FROM REQUEST WHERE SESSID=' . $this->index . " ORDER BY $order;");
            $info = $this->oPDO->errorInfo();
            $stmt->execute();
            $data = $stmt->fetchAll();
            $oResult = $this->formatData($data);   
            unset($oResult->totals);
            $stmt->closeCursor();
            
        } catch (Exception $e) {
            $oResult=new stdClass();            
        }
        return $oResult;
    }

    protected function init() {
        $sid = session_id();
        $rows = $this->oPDO->query('SELECT ID FROM SESSION WHERE SESSION="' . $sid . '";');
        if (!$this->isInit($rows)) {
            $time = time();
            $stmt = $this->oPDO->prepare('INSERT INTO SESSION ( SESSION,DATE) VALUES (:ssid,:dte);');
            $stmt->bindValue(':ssid', $sid, PDO::PARAM_STR);
            $stmt->bindValue(':dte', $time, PDO::PARAM_INT);
            $res = $stmt->execute();
            $stmt->closeCursor();
            $rows = $this->oPDO->query('SELECT ID FROM SESSION WHERE SESSION="' . $sid . '";');
            $this->isInit($rows);
        }
    }

    protected function sizeByMimeType($type, $size = 0) {
        if ($type == '') {
            $type = '-';
        }
        if (!isset($this->size[$type])) {
            $this->size[$type] = 0;
        }
        $this->size[$type] += $size;
        $this->totalSize += $size;
    }

    protected function formatData(&$data) {
        $this->size = array();
        $this->totalSize = 0;
        $output = array();
        foreach ($data as $row) {
            $oData = new stdClass();
            $oData->id = intval($row['REQID']);
            $oData->startTime = $row['STIME'];
            $time = floatval($row['TIME'] / 1000);
            if ($time > 1) {
                $time = sprintf('%02.3fs', $time);
            } else {
                $time = sprintf('%02.3fms', floatval($row['TIME']));
            }

            $oData->loadTime = $time;
            $oData->method = $row['METHOD'];
            $oData->type = $row['TYPE'];
            $oData->url = $row['URL'];
            $oData->status = intval($row['STATUS']);
            $size = intval($row['SIZE']);
            $this->sizeByMimeType($oData->type, $size);
            $oData->size = $this->calculateSize($size);
            $output[] = $oData;
        }
        $oResult = new stdClass();
        $oResult->request = $output;
        $oResult->totals = $this->getRequestSizeByType();
        return $oResult;
    }

    private function calculateSize(int $bytes = 0) {
        if ($bytes > (1024 * 1024)) {
            $size = sprintf('%02.2f MB', floatval($bytes) / (1024 * 1024));
        } elseif ($bytes > 1024) {
            $size = sprintf('%02.2f KB', floatval($bytes) / 1024);
        } else {
            $size = sprintf('%01u', $bytes);
        }
        return $size;
    }

    private function clearRequest() {
        $this->oPDO->query('DELETE FROM REQUEST WHERE SESSID=' . $this->index . ';');
        $this->size = array();
        $this->totalSize = 0;
    }

    private function createTableSESS() {
        $this->oPDO->query(self::SESS);
    }

    private function createTableREQUEST() {
        $this->oPDO->query(self::REQUEST);
    }

    private function isInit($rows) {
        $data = false;
        if ($rows !== false) {
            foreach ($rows as $row) {
                $data = true;
                $this->index = intval($row['ID']);
                continue;
            }
        }
        return $data;
    }

}
