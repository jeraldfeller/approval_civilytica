<?php

use Aiden\Models\Das;

class KuringgaiTask extends _BaseTask
{

    public $council_name = "Ku-ring-gai";
    public $council_website_url = "http://www.kmc.nsw.gov.au/Home";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    /**
     * This will set a cookie so we can scrape the DAs
     */
    public function acceptTerms($formData)
    {

        $url = "http://datracking.kmc.nsw.gov.au/datrackingUI/Modules/applicationmaster/default.aspx"
            . "?page=found"
            . "&1=thismonth"
            . "&4a=DA%27,%27Section96%27,%27Section82A%27,%27Section95a"
            . "&6=F";

        // Add extra values
        $formData["__EVENTTARGET"] = null;
        $formData["__EVENTARGUMENT"] = null;
        $formData['ctl00$cphContent$ctl00$Button1'] = "Agree";

        $formData = http_build_query($formData);

        $requestHeaders = [
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8",
            "Accept-Encoding: none",
            "Content-Type: application/x-www-form-urlencoded",
            "Content-Length: " . strlen($formData),
            "Host: datracking.kmc.nsw.gov.au",
            "Origin: http://datracking.kmc.nsw.gov.au"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
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

            $message = "cURL error: " . $errmsg . " (" . $errno . ")";
            $this->logger->error($message);
            return false;
        }

    }

    public function scrapeAction($params = [])
    {

        if (!isset($params[0])) {
            return false;
        }


        $numberOfPages = 30;
        $output = null;

        for ($i = 1; $i <= $numberOfPages; $i++) {
            if ($i == 1) {
                $url = 'https://eservices.kmc.nsw.gov.au/T1ePropertyProd/P1/eTrack/eTrackApplicationSearch.aspx?f=$P1.ETR.SEARCH.ENQ&Custom=Yes&ApplicationId=DA';
                $output = $this->getCurl($url);

            } else {
                $url = 'https://eservices.kmc.nsw.gov.au/T1ePropertyProd/P1/eTrack/eTrackApplicationSearchResults.aspx?r=KC_WEBGUEST&f=%24P1.ETR.RESULTS.VIW';
                $formData['__EVENTTARGET'] = 'ctl00$Content$cusResultsGrid$repWebGrid$ctl00$grdWebGridTabularView';
                $formData['__EVENTARGUMENT'] = 'Page$' . $i;
                $formData = http_build_query($formData);
                $requestHeaders = [
                    "Content-Type: application/x-www-form-urlencoded",
                    "Host: eservices.kmc.nsw.gov.au",
                    "Origin: https://eservices.kmc.nsw.gov.au",
                    "Referer: https://eservices.kmc.nsw.gov.au/T1ePropertyProd/P1/eTrack/eTrackApplicationSearchResults.aspx?r=KC_WEBGUEST&f=%24P1.ETR.RESULTS.VIW",
                    "Content-Length: " . strlen($formData)
                ];

                $output = $this->postCurl($url, $formData, $requestHeaders);
            }

            $formData = $this->getAspFormDataByString($output['output']);




            if ($output['errno'] !== 0) {
                $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $output['errmsg'], "errno" => $output['errno']]);
                return false;
            }

            $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output['output']);
            if (!$html) {
                $this->logger->error("Could not parse HTML");
                return false;
            }

            // We re-determine pagination using approach in planningalerts scraper
            // https://github.com/planningalerts-scrapers/city_of_ryde-1/blob/master/scraper.php
//            $numberOfPages = count($html->find("div[class=rgWrap rgNumPart] a")) ?: 1;

            $resultElements = $html->find("tr[class=normalRow], tr[class=alternateRow]");
            foreach ($resultElements as $resultElement) {
                $councilReferenceElement = $this->cleanString($resultElement->find('td', 0)->innertext());
                $daCouncilReference = $this->get_string_between($councilReferenceElement, 'value="', '" id="');
                $this->logger->info($daCouncilReference);
                $daCouncilUrl = 'https://eservices.kmc.nsw.gov.au/T1ePropertyProd/P1/eTrack/eTrackApplicationDetails.aspx?r=KC_WEBGUEST&f=$P1.ETR.APPDET.VIW&ApplicationId=' . urlencode($daCouncilReference);
                $this->logger->info($daCouncilUrl);
                $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                $da->setCouncilId($this->getCouncil()->getId());
                $da->setCouncilUrl($daCouncilUrl);
                $da->setCouncilReference($daCouncilReference);
                $this->saveDa($da);

            }
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }


    public function getCurl($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);

        return [
            'output' => $output,
            'errno' => $errno,
            'errmsg' => $errmsg
        ];
    }

    public function postCurl($url, $formData, $requestHeaders)
    {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);

        return [
            'output' => $output,
            'errno' => $errno,
            'errmsg' => $errmsg
        ];
    }

    protected function extractAddresses($html, $da, $params = null): bool
    {

        $addedAddresses = 0;
        $elements = $html->find("tr[class=normalRow], tr[class=alternateRow]");
        foreach ($elements as $row) {
            $header = trim($row->find('td', 0)->plaintext);
            if ($header == 'Address') {
                if ($row->find('td', 1)) {
                    $address = $row->find('td', 1)->innertext;
                    if ($address) {
                        $address = $this->cleanString($this->get_string_between($address, 'value="', '" id="'));
                        $this->logger->info($header . ': ' . $address);
                        if (strlen($address) > 0 && $this->saveAddress($da, $address)) {
                            $addedAddresses++;
                        }
                    }
                }

            }

        }


        return ($addedAddresses > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool
    {

        $elements = $html->find("tr[class=normalRow], tr[class=alternateRow]");
        foreach ($elements as $row) {
            $header = trim($row->find('td', 0)->plaintext);
            if ($header == 'Description') {
                if ($row->find('td', 1)) {
                    $value = $this->cleanString($row->find('td', 1)->innertext);
                    $this->logger->info($header.': '.$value);
                    return $this->saveDescription($da, $value);
                }

            }

        }

//        $detailsElement = $html->find("[id=lblDetails]", 0);
//        if ($detailsElement === null) {
//            return false;
//        }
//
//        $detailsString = $this->cleanString($detailsElement->innertext());
//        $detailsArray = explode("<br>", $detailsString);
//
//        foreach ($detailsArray as $detail) {
//
//            $regexPattern = '/Description: (.+)/';
//            if (preg_match($regexPattern, $detail, $matches) === 1) {
//                return $this->saveDescription($da, $this->cleanString($matches[1]));
//            }
//        }

        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool
    {

        $addedDocuments = 0;
        $url = 'https://eservicesdadocs.kmc.nsw.gov.au/WebGrid/?s=KCDocuments&Container='.urlencode($da->getCouncilReference()).'*';
        $this->logger->info('DOC URL' . ': ' . $url);
        $output = $this->scrapeTo($url, true);
        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output['output']);
        $info = explode( "\n", $output['info']['request_header'] );
        $aspCookie = '';
        for($ac = 0; $ac < count($info); $ac++){
            if(strpos($info[$ac], 'Cookie') !== false){
                $aspCookie = trim(str_replace('Cookie: ', '', $info[$ac]));
            }
        }
        $pageCount = 1;
        // count number of pages
        $rgNumPart = $html->find('.rgNumPart', 0);
        $targets = [];
        if($rgNumPart){
            $a = $rgNumPart->find('a');
            if( count($a) > 0){
                $pageCount = count($a);
                for($x = 1; $x < count($a); $x++){
                    $href = $a[$x]->getAttribute('href');
                    $targets[] = $this->get_string_between($href, "(&#39;", "&#39;,");
                }

            }
        }

        $formData = $this->getAspFormDataByString($output['output']);
        $elements = $html->find("tr[class=rgRow], tr[class=rgAltRow]");
        foreach ($elements as $row){
            $docTitle = $this->cleanString($row->find('td', 1)->plaintext);
            $docUrl = $this->cleanString($row->find('td', 3)->find('a',0)->getAttribute('href'));
            $documentDate = new \DateTime(date('Y-m-d'));
//            $this->logger->info($docTitle. ': ' . $docUrl);

            if ($this->saveDocument($da, $docTitle, $docUrl, $documentDate) === true) {
                    $addedDocuments++;
            }

        }




        if($pageCount > 1){
            for($i = 0; $i < count($targets); $i++){
//                $formData['WebGrid1_rsmWebGridControl_TSM'] = ';;System.Web.Extensions, Version=4.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35:en-US:b7585254-495e-4311-9545-1f910247aca5:ea597d4b:b25378d2;Telerik.Web.UI, Version=2016.3.1027.40, Culture=neutral, PublicKeyToken=121fae78165ba3d4:en-US:a5034868-8cfd-4375-ba8c-d3e7543c32f7:16e4e7cd:33715776:58366029';
                $formData['__EVENTTARGET'] = $targets[$i];

                $formData = http_build_query($formData);
                $requestHeaders = [
                ];

                $output = $this->postCurl($url, $formData, $requestHeaders);
                $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output['output']);
                $file = fopen("test.html", "w");
                echo fwrite($file, $output['output']);
                fclose($file);
                $elements = $html->find("tr[class=rgRow], tr[class=rgAltRow]");
                foreach ($elements as $row){
                    $docTitle = $this->cleanString($row->find('td', 1)->plaintext);
                    $docUrl = $this->cleanString($row->find('td', 3)->find('a',0)->getAttribute('href'));
                    $documentDate = new \DateTime(date('Y-m-d'));
                    $this->logger->info($docTitle. ': ' . $docUrl);

                    if ($this->saveDocument($da, $docTitle, $docUrl, $documentDate) === true) {
                        $addedDocuments++;
                    }

                }
            }
        }

//        $documentsElement = $html->find("[id=lblDocs]", 0);
//
//        if ($documentsElement === null) {
//            return false;
//        }
//
//        $regexPattern = '/(&diams; (.+?) \((.+?)\) (.+?) --&gt;&nbsp;\[)/';
//        $content = $documentsElement->plaintext;
//
//        $anchorElements = [];
//        foreach ($documentsElement->children() as $potentialAnchorElement) {
//
//            if ($potentialAnchorElement->tag !== "a") {
//                continue;
//            }
//
//            $anchorElements[] = $potentialAnchorElement;
//        }
//
//        if (preg_match_all($regexPattern, $content, $matches) !== 0) {
//
//            $amountOfDocuments = count($matches[0]);
//            for ($i = 0; $i < $amountOfDocuments; $i++) {
//
//                $documentName = $this->cleanString($matches[2][$i] . " " . $matches[4][$i]);
//                $documentDate = \DateTime::createFromFormat("d/m/Y", $matches[3][$i]);
//
//                $documentUrl = $this->cleanString($anchorElements[$i]->href);
//                $documentUrl = str_replace("../../", "/", $documentUrl);
//                $documentUrl = "http://datracking.kmc.nsw.gov.au/datrackingUI" . $documentUrl;
//
//                if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate) === true) {
//                    $addedDocuments++;
//                }
//            }
//        }

        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool
    {

        $costElement = $html->find("div[id=lblDim]", 0);

        if ($costElement === null) {
            return false;
        }

        return $this->saveEstimatedCost($da, $costElement->innertext());

    }

    protected function extractLodgeDate($html, $da, $params = null): bool
    {

        $elements = $html->find("tr[class=normalRow], tr[class=alternateRow]");
        foreach ($elements as $row) {
            $header = trim($row->find('td', 0)->plaintext);
            if ($header == 'Lodgement Date') {
                if ($row->find('td', 1)) {
                    $value = $this->cleanString($row->find('td', 1)->innertext);
                    $date = \DateTime::createFromFormat("d/m/Y",$value);
                    $this->logger->info($header.': '.$value);
                    return $this->saveLodgeDate($da, $date);
                }

            }

        }

//        $detailsElement = $html->find("[id=lblDetails]", 0);
//        if ($detailsElement === null) {
//            return false;
//        }
//
//        $detailsString = $this->cleanString($detailsElement->innertext());
//        $detailsArray = explode("<br>", $detailsString);
//
//        foreach ($detailsArray as $detail) {
//
//            $regexPattern = '/Submitted: ([0-9]{2}\/[0-9]{2}\/[0-9]{4})/';
//            if (preg_match($regexPattern, $detail, $matches) === 1) {
//
//                $date = \DateTime::createFromFormat("d/m/Y", $matches[1]);
//                return $this->saveLodgeDate($da, $date);
//            }
//        }

        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool
    {
        $elements = $html->find("tr[class=normalRow], tr[class=alternateRow]");
        foreach ($elements as $row) {
            $header = trim($row->find('td', 0)->plaintext);
            if ($header == 'Responsible Officer') {
                if ($row->find('td', 1)) {
                    $value = $this->cleanString($row->find('td', 1)->innertext);
                    if($value != ''){
                        $this->logger->info($header.': '.$value);
                        return $this->saveParty($da, 'Officer', $value);
                    }
                }

            }

        }

//        $officerElement = $html->find("div[id=lblOfficer]", 0);
//
//        if ($officerElement === null) {
//            return false;
//        }
//
//        $role = "Officer";
//        $name = $this->cleanString(strip_tags($officerElement->innertext()));
//        return $this->saveParty($da, $role, $name);
            return false;

    }

    protected function extractPeople($html, $da, $params = null): bool
    {

        $addedPeople = 0;
        $peepsElement = $html->find("[id=lblpeeps]", 0);

        if ($peepsElement === null) {
            return false;
        }

        $peepsHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($peepsElement->innertext());
        if ($peepsHtml === false) {
            return false;
        }

        $trElements = $peepsHtml->find("tr[class=tableLine]");
        foreach ($trElements as $trElement) {

            $roleElement = $trElement->children(0);
            if ($roleElement === null) {
                continue;
            }

            $nameElement = $trElement->children(1);
            if ($nameElement === null) {
                continue;
            }

            $role = $this->cleanString($roleElement->innertext());
            $name = $this->cleanString($nameElement->innertext());

            if (strlen($name) > 0 && $this->saveParty($da, $role, $name) === true) {
                $addedPeople++;
            }
        }

        return ($addedPeople > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool
    {
        return false;

    }

    function get_string_between($string, $start, $end)
    {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

    function scrapeTo($url, $showInfo = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);
        if($showInfo == true){
            curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        }


        $output = curl_exec($ch);
        if($showInfo == true){
            $information = curl_getinfo($ch);
        }
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        if($showInfo == true){
            return [
              'output' => $output,
                'info' => $information
            ];
        }else{
            return $output;
        }

    }

    function get_headers_from_curl_response($response)
    {
        $headers = array();

        $header_text = substr($response, 0, strpos($response, "\r\n\r\n"));

        foreach (explode("\r\n", $header_text) as $i => $line)
            if ($i === 0)
                $headers['http_code'] = $line;
            else
            {
                list ($key, $value) = explode(': ', $line);

                $headers[$key] = $value;
            }

        return $headers;
    }

}
