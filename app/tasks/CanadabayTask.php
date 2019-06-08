<?php

use Aiden\Models\Das;

class CanadabayTask extends _BaseTask {

    public $council_name = "Canada Bay";
    public $council_website_url = "http://www.canadabay.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    /**
     * Scrapes Canada Bay development applications
     */
    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $actualParam = "";
        switch ($params[0]) {
            case "thisweek":
                $actualParam = "TW";
                break;
            case "lastweek":
                $actualParam = "LW";
                break;
            case "thismonth":
                $actualParam = "TM";
                break;
            case "lastmonth":
                $actualParam = "LM";
                break;
            default:
                return false;
        }

        $url = "https://eservices.canadabay.nsw.gov.au/eProperty/P1/eTrack/eTrackApplicationSearchResults.aspx"
                . "?Field=S"
                . "&Period=" . $actualParam
                . "&r=P1.WEBGUEST"
                . "&f=%24P1.ETR.SEARCH.STW";

        $url = "http://datracking.canadabay.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx?d=thismonth&k=LodgementDate&t=DA";
        $url = "http://datracking.canadabay.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx?d=lastmonth&k=LodgementDate&t=DA";
        $logMsg = "URL: " . $url . "\r\n";
        $this->logger->info($logMsg);

//
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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

        $output = $this->get_string_between($output, '<div id="searchresult">', '</html>');

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

            $results = $html->find('.result');
            foreach($results as $row){
                $councilReference = $this->cleanString($row->find('.search', 0)->plaintext);
                $councilUrl = "http://datracking.canadabay.nsw.gov.au/".str_replace('../../', '', $this->cleanString($row->find('.search', 0)->getAttribute('href')));

                $da = Das::exists($this->getCouncil()->getId(), $councilReference) ?: new Das();
                $da->setCouncilId($this->getCouncil()->getId());
                $da->setCouncilReference($councilReference);
                $da->setCouncilUrl($councilUrl);
                if($this->saveDa($da)){
                    $daHtml = $this->curlTo($councilUrl);
                    $daHtml = $this->get_string_between($daHtml, 'id="bd"', 'class="footer');
                    $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($daHtml);
                    $this->scrapeMeta($daHtml, $da);
                }
                $this->logger->info($councilReference . ' ' . $councilUrl);

            }


        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
//        $this->scrapeMetaAction();
        $logMsg = "Done.";
        $this->logger->info($logMsg);

    }


    public function scrapeMeta($html, $da, $suburb = null) {

        // Weird Phalcon bug doesn't allow us to save model twice without errors.
        $oldDaId = $da->getId();
        unset($da);
        $da = Das::findFirstById($oldDaId);
        if ($da === false) {
            $this->logger->critical("Could not find related development application in scrapeMeta()-method.");
            return false;
        }

        // Extract addresses is a core method, without it don't run.
        if (method_exists($this, "extractAddresses")) {

            $extractedAddresses = $this->extractAddresses($html, $da, $suburb);
            if ($extractedAddresses === false) {
                $this->logger->error("Did not find any addresses related to this DA, something went wrong.");
                return false;
            }
        }
        else {

            $this->logger->critical("Current class is not being able to extract address(es).");
            return false;
        }

        foreach ($this->getRequiredMethods() as $method) {
            if (method_exists($this, $method)) {
                $this->{$method}($html, $da);
            }
        }

        $da->setCrawled(true);
        if ($da->save()) {
            $this->logger->info("Finished.");
        }
        else {
            $this->logger->info("Something went wrong when trying to update crawl status ({error})", [
                "error" => print_r($da->getMessages(), true)
            ]);
        }

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $container = $html->find('#addr', 0);
        $addresses = $container->find('.detailright');
        foreach ($addresses as $row){
            $a = $row->find('a', 0);
            if($a){
                $address = $this->cleanString($a->innertext());
                if ($this->saveAddress($da, $address) === true) {
                    $addedAddresses++;
                }
            }
        }
        return ($addedAddresses > 0);

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedParties = 0;
        $container = $html->find('#ppl', 0);
        if($container){
            $details = $container->find('.detailright');
            if($details){
                foreach ($details as $d){
                    $detail = $d->innertext();
                    $detailArr = explode("<br />", $detail);
                    for($x = 0; $x < count($detailArr); $x++){
                        $rolePeople = explode('-', $detailArr[$x]);
                        $role = $this->cleanString($rolePeople[0]);
                        if($role != ''){
                            $name = $this->cleanString($rolePeople[1]);
                            $this->logger->info($role . ' - ' . $name);
                            if ($this->saveParty($da, $role, $name)) {
                                $addedParties++;
                            }
                        }
                    }
                }
            }
        }
        return ($addedParties > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {


        $container = $html->find('#b_ctl00_ctMain_info_app', 0);
        if($container){
            $info = $container->innertext();
            $infoArr = explode('<br />', $info);
            $description = $this->cleanString($infoArr[0]);
            if (strlen($description) > 0) {
                return $this->saveDescription($da, $description);
            }
        }

        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $container = $html->find('#b_ctl00_ctMain_info_app', 0);
        if($container){
            $info = $container->innertext();
            $infoArr = explode('<br />', $info);
            for($x = 0; $x < count($infoArr); $x++){

                if(strpos($infoArr[$x], 'Lodged') !== false){
                    $lodgeInfo = explode(':', $infoArr[$x]);
                    $lodgeDate = $this->cleanString(($lodgeInfo[1] != '' ? $lodgeInfo[1] : false));
                    if($lodgeDate){
                        $date = \DateTime::createFromFormat("d/m/Y", $lodgeDate);
                        return $this->saveLodgeDate($da, $date);
                    }

                }
            }
        }
        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {
        $this->logger->info($da->getCouncilUrl());
        $addedDocuments = 0;
        $container = $html->find('#b_ctl00_ctMain_info_docs', 0);
        if($container){
            $table = $container->find('table', 0);
            if($table){
                $tr = $table->find('tr');
                foreach($tr as $row){
                    if($row->find('td', 0)->innertext() != ''){
                        $documentUrl = "http://datracking.canadabay.nsw.gov.au/".$this->cleanString(str_replace('../../', '', $row->find('td', 0)->find('a', 0)->getAttribute('href')));
                        $documentName = $this->cleanString($row->find('td', 1)->innertext());
                        $created = $this->cleanString($row->find('td', 2)->innertext());

                        $this->logger->info($documentName . ' - ' . $documentUrl);
                        if($created != ''){
                            $documentDate = \DateTime::createFromFormat("d/m/Y", $created);
                        }


                        if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate)) {
                            $addedDocuments++;
                        }
                    }
                }
            }
        }
        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {
        $container = $html->find('#b_ctl00_ctMain_info_app', 0);
        if($container){
            $info = $container->innertext();
            $infoArr = explode('<br />', $info);
            for($x = 0; $x < count($infoArr); $x++){

                if(strpos($infoArr[$x], 'Estimated Cost') !== false){
                    $costInfo = explode(':', $infoArr[$x]);
                    $costInfo = $this->cleanString(($costInfo[1] != '' ? $costInfo[1] : false));
                    if($costInfo){
                        return $this->saveEstimatedCost($da, $costInfo);
                    }

                }
            }
        }
        return false;

    }


    function get_string_between($string, $start, $end){
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

    public function curlTo($url){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        return $output;
    }

}
