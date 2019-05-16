<?php

use Aiden\Models\Das;

class KuringgaiTask extends _BaseTask {

    public $council_name = "Ku-ring-gai";
    public $council_website_url = "http://www.kmc.nsw.gov.au/Home";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    /**
     * This will set a cookie so we can scrape the DAs
     */
    public function acceptTerms($formData) {

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

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $url = "http://datracking.kmc.nsw.gov.au/datrackingUI/Modules/applicationmaster/default.aspx"
            . "?page=found"
            . "&1=" . $params[0]
            . "&4a=DA%27,%27Section96%27,%27Section82A%27,%27Section95a"
            . "&6=F";

        $this->logger->info($url);

        $this->acceptTerms($this->getAspFormDataByUrl($url));

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

            // First request is GET, after start POSTing
            if ($i > 0) {

                $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
                if (!$html) {

                    $message = "Could not parse HTML";
                    $this->logger->error($message);
                    continue;
                }

                $eventTarget = substr($html->find("div[class=rgWrap rgNumPart] a", $i)->href, 25, 61);

                $formData = $this->getAspFormDataByString($output);
                $formData["__EVENTTARGET"] = $eventTarget;
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
                $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
                return false;
            }

            $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
            if (!$html) {
                $this->logger->error("Could not parse HTML");
                return false;
            }

            // We re-determine pagination using approach in planningalerts scraper
            // https://github.com/planningalerts-scrapers/city_of_ryde-1/blob/master/scraper.php
            $numberOfPages = count($html->find("div[class=rgWrap rgNumPart] a")) ?: 1;

            $resultElements = $html->find("tr[class=rgRow], tr[class=rgAltRow]");
            foreach ($resultElements as $resultElement) {

                $anchorElement = $resultElement->children(0)->children(0);
                $anchorUrl = $this->cleanString($anchorElement->href);

                $daCouncilUrl = "http://datracking.kmc.nsw.gov.au/datrackingUI/Modules/applicationmaster/" . $anchorUrl;
                $daCouncilReference = $this->cleanString($resultElement->children(1)->innertext());

                $urlParts = explode("=", $anchorUrl);
                $daCouncilReferenceAlt = $urlParts[count($urlParts) - 1];

                $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                $da->setCouncilId($this->getCouncil()->getId());
                $da->setCouncilUrl($daCouncilUrl);
                $da->setCouncilReference($daCouncilReference);
                $da->setCouncilReferenceAlt($daCouncilReferenceAlt);
                $this->saveDa($da);
            }
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $propertiesElement = $html->find("[id=lblProp]", 0);

        if ($propertiesElement === null) {
            return false;
        }

        foreach ($propertiesElement->children() as $propertyElement) {

            $address = $this->cleanString($propertyElement->innertext());
            if (strlen($address) > 0 && $this->saveAddress($da, $address)) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=lblDetails]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($detailsElement->innertext());
        $detailsArray = explode("<br>", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Description: (.+)/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {
                return $this->saveDescription($da, $this->cleanString($matches[1]));
            }
        }

        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $documentsElement = $html->find("[id=lblDocs]", 0);

        if ($documentsElement === null) {
            return false;
        }

        $regexPattern = '/(&diams; (.+?) \((.+?)\) (.+?) --&gt;&nbsp;\[)/';
        $content = $documentsElement->plaintext;

        $anchorElements = [];
        foreach ($documentsElement->children() as $potentialAnchorElement) {

            if ($potentialAnchorElement->tag !== "a") {
                continue;
            }

            $anchorElements[] = $potentialAnchorElement;
        }

        if (preg_match_all($regexPattern, $content, $matches) !== 0) {

            $amountOfDocuments = count($matches[0]);
            for ($i = 0; $i < $amountOfDocuments; $i++) {

                $documentName = $this->cleanString($matches[2][$i] . " " . $matches[4][$i]);
                $documentDate = \DateTime::createFromFormat("d/m/Y", $matches[3][$i]);

                $documentUrl = $this->cleanString($anchorElements[$i]->href);
                $documentUrl = str_replace("../../", "/", $documentUrl);
                $documentUrl = "http://datracking.kmc.nsw.gov.au/datrackingUI" . $documentUrl;

                if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate) === true) {
                    $addedDocuments++;
                }
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $costElement = $html->find("div[id=lblDim]", 0);

        if ($costElement === null) {
            return false;
        }

        return $this->saveEstimatedCost($da, $costElement->innertext());

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=lblDetails]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($detailsElement->innertext());
        $detailsArray = explode("<br>", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Submitted: ([0-9]{2}\/[0-9]{2}\/[0-9]{4})/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {

                $date = \DateTime::createFromFormat("d/m/Y", $matches[1]);
                return $this->saveLodgeDate($da, $date);
            }
        }

        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {

        $officerElement = $html->find("div[id=lblOfficer]", 0);

        if ($officerElement === null) {
            return false;
        }

        $role = "Officer";
        $name = $this->cleanString(strip_tags($officerElement->innertext()));
        return $this->saveParty($da, $role, $name);

    }

    protected function extractPeople($html, $da, $params = null): bool {

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

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

}
