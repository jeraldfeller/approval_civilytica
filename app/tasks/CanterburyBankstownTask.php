<?php

use Aiden\Models\Das;

class CanterburyBankstownTask extends _BaseTask {

    public $council_name = "Canterbury Bankstown";
    public $council_website_url = "https://www.cbcity.nsw.gov.au/development";
    public $council_params = ["thisweek", "thismonth"];
    public $council_default_param = "thismonth";
    public $canterbury_bankstown_id = 34;

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $url = "http://eplanning.cbcity.nsw.gov.au/ApplicationSearch/ApplicationSearchThroughLodgedDate?day=thismonth";

        $this->logger->info($url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        $table = $html->find('table', 0);
        if($table){
            $tr = $table->find('tr', 0);
            if($tr){
                $td = $tr->find('td', 1);
                if($td){
                    $div = $td->find('div');
                    foreach($div as $row){
                        $h4 = $row->find('h4', 0);
                        if($h4){
                            $daCouncilReference = $h4->find('a', 0)->innertext();
                            $daCouncilUrl = 'http://eplanning.cbcity.nsw.gov.au'.$h4->find('a', 0)->getAttribute('href');
                            $da = Das::exists($this->canterbury_bankstown_id, $daCouncilReference) ?: new Das();
                            $da->setCouncilId($this->canterbury_bankstown_id);
                            $da->setCouncilReference($daCouncilReference);
                            $da->setCouncilUrl($daCouncilUrl);
                            $this->saveDa($da);
                        }

                    }
                }
            }
        }


        $this->getCouncil()->setLastScrape(new \DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $logMsg = "Done.";
        $this->logger->info($logMsg);

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $rows = $html->find(".row");
        foreach($rows as $row){
            $b = $row->find('b', 0);
            if($b){
                $tableHeader = trim($row->find('b', 0)->innerText());

                if($tableHeader == 'Address:'){
                    $this->logger->info($tableHeader);
                    $addressElement = $row->find('div');
                    foreach ($addressElement as $add){
                        $address = $add->innerText();
                        if(strpos($address, 'Address') === false){
                            $this->logger->info($address);
                            if ($this->saveAddress($da, $this->cleanString($address))) {
                                $addedAddresses++;
                            }
                        }
                    }
                }
            }
        }
        return ($addedAddresses > 0);
    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        return false;

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $rows = $html->find(".row");
        foreach($rows as $row){
            $b = $row->find('b', 0);
            if($b){
                $tableHeader = trim($row->find('b', 0)->innerText());

                if($tableHeader == 'Description:'){
                    $this->logger->info($tableHeader);
                    $element = $row->find('div', 1);
                    if($element){
                        $value = $element->innerText();
                        $this->logger->info($value);
                        return $this->saveDescription($da, $this->cleanString($value));
                    }
                }
            }
        }

        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedParties = 0;

        $container = $html->find('#People', 0);
        if($container){
            $table = $container->find('table', 0);
            if($table){
                $tr = $table->find('tr');
                foreach ($tr as $row){
                    $th = $row->find('th', 0);
                    if($th){
                        $header = trim($th->innerText());
                        $this->logger->info($header);
                        $td = $row->find('td', 0);
                        if($td){
                            if($header == 'People:'){
                                $value = $td->innerText();
                                // get Applicants
                                $applicantValue = trim($this->get_string_between($value, 'Applicant:', 'Owner:'));
                                $applicantValueExplode = explode('<br />', $applicantValue);
                                for($i = 0; $i < count($applicantValueExplode); $i++){
                                    $name = trim($this->cleanString($applicantValueExplode[$i]));
                                    $this->logger->info('Applicant: ' . $name);
                                    if($name != ''){
                                        if ($this->saveParty($da, 'Applicant', $name)) {
                                            $addedParties++;
                                        }
                                    }
                                }

                                // get Owners
                                if (($pos = strpos($value, "Owner:")) !== FALSE) {
                                    $owners = substr($value, $pos+6);
                                    $ownersExplode = explode('<br />', $owners);
                                    for($i = 0; $i < count($ownersExplode); $i++){
                                        $name = trim($this->cleanString($ownersExplode[$i]));
                                        $this->logger->info('Owner: ' . $name);
                                        if($name != ''){
                                            if ($this->saveParty($da, 'Owner', $name)) {
                                                $addedParties++;
                                            }
                                        }
                                    }

                                }
                            }else if($header == 'Officer(s):'){
                                $value = $td->innerText();
                                $officerValue = trim($value);
                                $officerValueExplode = explode('<br />', $officerValue);
                                for($i = 0; $i < count($officerValueExplode); $i++){
                                    $name = trim($this->cleanString($officerValueExplode[$i]));
                                    if($name != ''){
                                        if ($this->saveParty($da, 'Officer', $name)) {
                                            $addedParties++;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return ($addedParties > 0);

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;

        $container = $html->find('#Documents', 0);
        if($container){
            $table = $container->find('table', 0);
            if($table){
                $tr = $table->find('tr', 0);
                if($tr){
                    $td = $tr->find('td', 0);
                    if($td){
                        $a = $td->find('a');
                        $this->logger->info('Documents: ');
                        foreach($a as $row){
                            $date =  new DateTime();
                            $documentUrl = $this->cleanString($row->getAttribute('href'));
                            $documentName = $this->cleanString($row->plaintext);
                            if ($this->saveDocument($da, $documentName, $documentUrl, $date)) {
                                $addedDocuments++;
                            }
                            $this->logger->info($documentName . ' - ' . $documentUrl);

                        }
                    }
                }
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $rows = $html->find(".row");
        foreach($rows as $row){
            $b = $row->find('b', 0);
            if($b){
                $tableHeader = trim($row->find('b', 0)->innerText());

                if($tableHeader == 'Lodged:'){
                    $this->logger->info($tableHeader);
                    $element = $row->find('div', 1);
                    if($element){
                        $value = $element->innerText();
                        $value = explode(' ', $value);
                        $daLodgeDate = \DateTime::createFromFormat("d/m/Y", $value[0]);
                        return $this->saveLodgeDate($da,$daLodgeDate);
                    }
                }
            }
        }
    }


    public function get_string_between($string, $start, $end){
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }


}