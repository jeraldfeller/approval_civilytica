<?php

use Aiden\Models\Das;

class RydeTask extends _BaseTask {

    public $council_name = "Ryde";
    public $council_website_url = "https://www.ryde.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }
        $dateFilter = 'TM';
        switch ($params[0]){
            case 'thismonth':
                $dateFilter = 'TM';
                break;
            case 'thisweek':
                $dateFilter = 'TW';
                break;
            case 'lastweek':
                $dateFilter = 'LW';
                break;
            case 'lastmonth':
                $dateFilter = 'LM';
                break;
        }


        $url = 'https://eservices.ryde.nsw.gov.au/T1PRProd/WebApps/eProperty/P1/eTrack/eTrackApplicationSearchResults.aspx?Field=S&Period='.$dateFilter.'&r=COR.P1.WEBGUEST&f=$P1.ETR.SEARCH.STM';

        $this->logger->info($url);

        $numberOfPages = 1;
        $output = null;
        for ($i = 0; $i < $numberOfPages; $i++) {
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


            // First request is GET, after start POSTing
            if ($i > 0) {
                $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
                if (!$html) {
                    $this->logger->error("Could not parse HTML");
                    continue;
                }
                $eventTarget = 'Page$'.($i+1);
                $this->logger->info('POST: '. $eventTarget);
                $formData = $this->getAspFormDataByString($output);
                $formData["__EVENTARGUMENT"] = $eventTarget;
                $formData["__EVENTTARGET"] = 'ctl00$Content$cusResultsGrid$repWebGrid$ctl00$grdWebGridTabularView';
                $formData = http_build_query($formData);

                $requestHeaders = [
                    "Content-Type: application/x-www-form-urlencoded",
                    "Content-Length: " . strlen($formData)
                ];

                curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
            }

            $output = curl_exec($ch);
            $errno = curl_errno($ch);
            $errmsg = curl_error($ch);
            curl_close($ch);

            if ($errno !== 0) {
                $this->logger->error("cURL error on page {page}: {errmsg} ({errno})", [
                    "page" => $i + 1,
                    "errmsg" => $errmsg,
                    "errno" => $errno
                ]);
                return false;
            }

            $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
            if (!$html) {
                $this->logger->error("Could not parse HTML");
                return false;
            }

            // We re-determine pagination using approach in planningalerts scraper
            // https://github.com/planningalerts-scrapers/city_of_ryde-1/blob/master/scraper.php
//            $numberOfPages = count($html->find(".pageRow td table td")) ?: 1;
            $tableFooter = $html->find(".pagerRow", 0);
            if($tableFooter != null){
                $numberOfPages = count($html->find(".pagerRow", 0)->find('table', 0)->find('td'));
            }else{
                $numberOfPages = 1;
            }

            $daRowElements = $html->find("tr[class=normalRow], tr[class=alternateRow]");
            foreach ($daRowElements as $daRowElement) {
                $firstRow = $daRowElement->find('td', 0);
                $script = $firstRow->find('script', 0);
                $councilReferenceInnerText = trim($script->innertext());
                $councilReference = $this->get_string_between($councilReferenceInnerText, '">', '</a>');

                $daCouncilUrl = 'https://eservices.ryde.nsw.gov.au/T1PRProd/WebApps/eProperty/P1/eTrack/eTrackApplicationDetails.aspx?r=COR.P1.WEBGUEST&f=$P1.ETR.APPDET.VIW&ApplicationId='.$this->cleanString($councilReference);
                $daCouncilReference = $this->cleanString($councilReference);

                $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                $da->setCouncilId($this->getCouncil()->getId());
                $da->setCouncilReference($daCouncilReference);
                $da->setCouncilUrl($daCouncilUrl);
                $this->saveDa($da);
            }
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addressesElement = $html->find("[id=ctl00_Content_cusPageComponents_repPageComponents_ctl01_cusPageComponentGrid_pnlCustomisationGrid]", 0);
        if ($addressesElement === null) {
            return false;
        }

        // first row address, second lot
        $address = '';
        if($addressesElement->find('td', 1)){
            $address = $addressesElement->find('td', 1)->plaintext;
        }
        if($addressesElement->find('td', 3)){
            $address .= ', '.$addressesElement->find('td', 3)->plaintext;
        }

        if($address == ''){
            return false;
        }

        return $this->saveAddress($da, $address);

    }

    protected function extractApplicants($html, $da, $params = null): bool {

        $addedApplicants = 0;
        $applicantElement = $html->find("div[id=ctl00_Content_cusPageComponents_repPageComponents_ctl03_pnlComponent]", 0);

        if ($applicantElement === null) {
            return false;
        }

        // iterate applicant rows
        $tr = $applicantElement->find('tr');
        if(count($tr) > 0){
            for($x = 0; $x < count($tr); $x++){
                $title = trim($tr[$x]->find('td', 0)->plaintext);
                if($title == 'Name'){
                    $name = $this->cleanString(trim($tr[$x]->find('td', 1)->plaintext));
                }
                if($title == 'Association'){
                    $role = $this->cleanString(trim($tr[$x]->find('td', 1)->plaintext));
                    if($role == 'Applicant'){
                        if ($this->saveParty($da, 'Applicant', $name)) {
                            $addedApplicants++;
                        }
                    }
                }
            }
        }else{
            return false;
        }
        return ($addedApplicants > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=ctl00_Content_cusPageComponents_repPageComponents_ctl00_cusPageComponentGrid_repWebGrid_ctl00_dtvWebGridListView]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $tr = $detailsElement->find('tr');
        $description = '';
        if(count($tr) > 0){
            for($x = 0; $x < count($tr); $x++){
                $title = trim($tr[$x]->find('td', 0)->plaintext);
                if($title == 'Description'){
                    $description = $this->cleanString($tr[$x]->find('td', 1)->plaintext);


                    return $this->saveDescription($da, $description);
                }
            }
        }else{
            return false;
        }

        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {
        return false;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {
        $this->logger->info("COST");
        $costElement = $html->find("[id=ctl00_Content_cusPageComponents_repPageComponents_ctl00_cusPageComponentGrid_repWebGrid_ctl00_dtvWebGridListView]", 0);
        if ($costElement === null) {
            return false;
        }
        $tr = $costElement->find('tr');
        if(count($tr) > 0){
            for($x = 0; $x < count($tr); $x++){
                $title = trim($tr[$x]->find('td', 0)->plaintext);
                if($title == 'Estimated Cost'){
                    $cost = trim($tr[$x]->find('td', 1)->plaintext);

                    return $this->saveEstimatedCost($da, $cost);
                }
            }
        }else{
            return false;
        }
    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=ctl00_Content_cusPageComponents_repPageComponents_ctl00_cusPageComponentGrid_repWebGrid_ctl00_dtvWebGridListView]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $tr = $detailsElement->find('tr');
        if(count($tr) > 0){
            for($x = 0; $x < count($tr); $x++){
                $title = trim($tr[$x]->find('td', 0)->plaintext);
                if($title == 'Lodgement Date'){
                    $date = \DateTime::createFromFormat("d/m/Y", trim($tr[$x]->find('td', 1)->plaintext));
                    return $this->saveLodgeDate($da, $date);
                }
            }
        }

        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {

        $officerElement = $html->find("div[id=ctl00_Content_cusPageComponents_repPageComponents_ctl03_pnlComponent]", 0);

        if ($officerElement === null) {
            return false;
        }


        // iterate applicant rows
        $tr = $officerElement->find('tr');
        if(count($tr) > 0){
            for($x = 0; $x < count($tr); $x++){
                $title = trim($tr[$x]->find('td', 0)->plaintext);
                if($title == 'Name'){
                    $name = $this->cleanString(trim($tr[$x]->find('td', 1)->plaintext));
                }
                if($title == 'Association'){
                    $role = $this->cleanString(trim($tr[$x]->find('td', 1)->plaintext));
                    if($role == 'Officer'){
                        return $this->saveParty($da, $role, $name);
                    }
                }
            }
            return false;
        }else{
            return false;
        }
    }

    protected function extractPeople($html, $da, $params = null): bool {
        return false;

    }

    protected function get_string_between($string, $start, $end){
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

}
