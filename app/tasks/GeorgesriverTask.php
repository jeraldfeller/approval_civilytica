<?php

use Aiden\Models\Das;

class GeorgesriverTask extends _BaseTask {

    public $council_name = "Georges River";
    public $council_website_url = "http://www.georgesriver.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    /**
     * This will set a cookie so we can scrape the DAs
     */
    public function acceptTerms($formData) {

        $url = "http://daenquiry.georgesriver.nsw.gov.au/masterviewui/Modules/applicationmaster/Default.aspx";

        $formData["__EVENTTARGET"] = null;
        $formData["__EVENTARGUMENT"] = null;
        $formData['ctl00$cphContent$ctl00$Button1'] = "Agree";
        $formData = http_build_query($formData);

        $requestHeaders = [
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8",
            "Accept-Encoding: none",
            "Content-Type: application/x-www-form-urlencoded",
            "Content-Length: " . strlen($formData),
            "Host: daenquiry.georgesriver.nsw.gov.au",
            "Origin: http://daenquiry.georgesriver.nsw.gov.au"
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
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

    }

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $url = "http://daenquiry.georgesriver.nsw.gov.au/masterviewui/Modules/applicationmaster/default.aspx"
                . "?page=found"
                . "&1=" . $params[0]
                . "&4a=DA%27%2C%27S96Mods%27%2C%27Mods%27%2C%27Reviews"
                . "&6=F";

        $this->logger->info("URL: {url}", ["url" => $url]);

        // Send request to page, retrieve cookie, access DAs, profit ????
        if ($this->acceptTerms($this->getAspFormDataByUrl($url)) === false) {
            return false;
        }

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

            // After first GET request, start POSTing
            if ($i > 0) {

                $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
                if (!$html) {

                    $logMsg = "Could not parse HTML";
                    $this->logger->info($logMsg);
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
                $daCouncilUrl = "http://daenquiry.georgesriver.nsw.gov.au/masterviewui/Modules/applicationmaster/" . $this->cleanString($anchorElement->href);

                $daCouncilReference = $this->cleanString($resultElement->children(1)->innertext());

                $urlExploded = explode("=", $daCouncilUrl);
                $daCouncilReferenceAlt = $urlExploded[count($urlExploded) - 1];

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

        $addressesElement = $html->find("[id=lblProp]", 0);
        if ($addressesElement === null) {
            return false;
        }

        $addressesString = $this->cleanString($addressesElement->innertext());
        $addressesArray = explode("<br>", $addressesString);
        foreach ($addressesArray as $daAddress) {

            if (strlen($daAddress) === 0) {
                continue;
            }

            if ($this->saveAddress($da, $daAddress)) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {

        $addedApplicants = 0;
        $applicantElement = $html->find("div[id=lblPeople]", 0);

        if ($applicantElement === null) {
            return false;
        }

        $applicantsString = $this->cleanString($applicantElement->innertext());
        $applicantsArray = explode("<br />", $applicantsString);

        foreach ($applicantsArray as $daApplicant) {

            if (strlen($daApplicant) === 0) {
                continue;
            }

            $role = "Applicant";
            $name = $this->cleanString($daApplicant);

            if ($this->saveParty($da, $role, $name)) {
                $addedApplicants++;
            }
        }

        return ($addedApplicants > 0);

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

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $costElement = $html->find("div[id=lblDim]", 0);

        if ($costElement === null) {
            return false;
        }

        return $this->saveEstimatedCost($da, $costElement->innertext());

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $documentsElement = $html->find("[id=lblDocuments]", 0);

        if ($documentsElement === null) {
            return false;
        }

        $regexPattern = '/\x{2666}\s([A-Z]{1}[0-9]{2}\/[0-9]{6})\s(.+?) ([0-9]{2}\/[0-9]{2}\/[0-9]{4})/u';
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

                $documentName = $this->cleanString($matches[1][$i] . " " . $matches[2][$i]);
                $documentDate = \DateTime::createFromFormat("d/m/Y", $matches[3][$i]);

                $documentUrl = $this->cleanString($anchorElements[$i]->href);
                $documentUrl = str_replace("../../", "/", $documentUrl);
                $documentUrl = "http://daenquiry.georgesriver.nsw.gov.au/masterviewui" . $documentUrl;

                if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate) === true) {
                    $addedDocuments++;
                }
            }
        }

        return ($addedDocuments > 0);

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

    protected function extractPeople($html, $da, $params = null): bool {
        return false;

    }

}
