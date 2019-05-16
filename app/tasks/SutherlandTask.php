<?php

use Aiden\Models\Das;

class SutherlandTask extends _BaseTask {

    public $council_name = "Sutherland";
    public $council_website_url = "www.sutherlandshire.nsw.gov.au/";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

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

        $url = "https://propertydevelopment.ssc.nsw.gov.au/T1PRPROD/WebApps/eproperty/P1/eTrack/eTrackApplicationSearchResults.aspx"
                . "?Field=S"
                . "&Period=" . $actualParam
                . "&Group=DA"
                . "&SearchFunction=SSC.P1.ETR.SEARCH.DA"
                . "&r=SSC.P1.WEBGUEST"
                . "&f=SSC.ETR.SRCH.STW.DA"
                . "&ResultsFunction=SSC.P1.ETR.RESULT.DA";

        // Get asp vars for initial request, after each request we update this.
        $formData = $this->getAspFormDataByUrl($url);

        $currentPage = 1;
        $haveNotSeenResultsCount = 0;
        $lastPageHash = null;

        while (true) {

            if ($haveNotSeenResultsCount > 5) {
                break;
            }

            // Add page parameter to form data
            $formData['ctl00$Content$cusResultsGrid$repWebGrid$ctl00$grdWebGridTabularView$ctl18$ctl02'] = $currentPage;
            $formData = http_build_query($formData);

            $requestHeaders = [
                "Accept: */*; q=0.8",
                "Accept-Encoding: none",
                "Accept-Language: en-GB,en-US;q=0.9,en;q=0.8",
                "Content-Length: " . strlen($formData),
                "Content-Type: application/x-www-form-urlencoded",
            ];

            // Performing POST request will give us DAs
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

            // Remember ASP vars for next request
            $formData = $this->getAspFormDataByString($output);

            // MD5 the page output so we can check if it changed.
            // If it's the same page nothing changed.
            $pageHash = md5($output);
            if ($lastPageHash === $pageHash) {
                break;
            }

            $lastPageHash = $pageHash;

            $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
            if (!$html) {

                $haveNotSeenResultsCount++;
                continue;
            }

            // See if we can find the table
            $tableElement = $html->find("table[class=grid]", 0);
            if (!$tableElement) {

                $currentPage++;
                $haveNotSeenResultsCount++;
                continue;
            }

            $tableRowElements = $tableElement->children();

            // Loop through table's children
            $seenResults = false;
            foreach ($tableRowElements as $tableRowElement) {

                // Skip header rows etc.
                if ($tableRowElement->class !== "normalRow" && $tableRowElement->class !== "alternateRow") {
                    continue;
                }

                $seenResults = true;

                $firstChildElement = $tableRowElement->children(0);
                if ($firstChildElement === null) {
                    continue;
                }

                $noscriptElement = $firstChildElement->children(1);
                if ($noscriptElement === null) {
                    continue;
                }

                $divElement = $noscriptElement->children(0);
                if ($divElement === null) {
                    continue;
                }

                $inputElement = $divElement->children(0);
                if ($inputElement === null) {
                    continue;
                }

                $daCouncilReference = $this->cleanString($inputElement->value);
                $daCouncilUrl = 'https://propertydevelopment.ssc.nsw.gov.au'
                        . '/T1PRPROD/WebApps/eproperty/P1/eTrack/eTrackApplicationSearchResults.aspx/eTrackApplicationDetails.aspx'
                        . '?r=SSC.P1.WEBGUEST'
                        . '&f=$P1.ETR.APPDET.VIW'
                        . '&ApplicationId=' . urlencode($daCouncilReference);

                $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                $da->setCouncilId($this->getCouncil()->getId());
                $da->setCouncilReference($daCouncilReference);
                $da->setCouncilUrl($daCouncilUrl);
                $this->saveDa($da);
            }

            if ($seenResults === false) {
                $haveNotSeenResultsCount++;
            }
            $currentPage++;
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;

        $tableHeaderElements = $html->find("th[scope=col]");
        foreach ($tableHeaderElements as $tableHeaderElement) {

            $tableHeaderText = $this->cleanString($tableHeaderElement->innertext());
            if (strpos(strtolower($tableHeaderText), "address") === false) {
                continue;
            }

            $nextTableHeaderElement = $tableHeaderElement->next_sibling();
            if ($nextTableHeaderElement === null) {
                continue;
            }

            $nextTableHeaderText = $this->cleanString($nextTableHeaderElement->innertext());
            if (preg_match("/land description/i", $nextTableHeaderText) === 0) {
                continue;
            }

            $headerParentElement = $tableHeaderElement->parent();
            if ($headerParentElement === null) {
                continue;
            }

            $valueRowElement = $headerParentElement->next_sibling();
            if ($valueRowElement === null) {
                continue;
            }

            $addressValueElement = $valueRowElement->children(0);
            if ($addressValueElement === null) {
                continue;
            }

            $address = $this->cleanString($addressValueElement->innertext());

            $lotValueElement = $valueRowElement->children(1);
            if ($lotValueElement !== null) {

                $lot = $this->cleanString($lotValueElement->innertext());
                $address = $lot . ", " . $address;
            }

            if ($this->saveAddress($da, $address)) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=headerColumn]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (strpos(strtolower($headerText), "description") === false) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return $this->saveDescription($da, $value);
        }

        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;

        $anchorElements = $html->find("a");
        foreach ($anchorElements as $anchorElement) {

            $href = $this->cleanString($anchorElement->href);

            $regexPattern = '/webservice\.ssc\.nsw\.gov\.au\/ETrack\/default\.aspx\?page=dms&ctr=([0-9]+)&id=(.+)/';
            if (preg_match($regexPattern, $href, $matches) === 0) {
                continue;
            }

            $documentsUrl = "https://webservice.ssc.nsw.gov.au/ETrack/default.aspx"
                    . "?page=dms"
                    . "&ctr=" . $matches[1]
                    . "&id=" . $matches[2];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $documentsUrl);
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

            $docsRegexPattern = '/(([0-9]{1,2}\/[0-9]{2}\/[0-9]{4}) -\s+<a href="(.+?)" target="_blank" ?>(.+?)<\/a>)/';
            if (preg_match_all($docsRegexPattern, $output, $matches) === 1) {

                for ($i = 0; $i < count($matches[0]); $i++) {

                    $date = $this->cleanString($matches[2][$i]);
                    $documentDate = \DateTime::createFromFormat("d/m/Y", $date);

                    $documentUrl = $this->cleanString($matches[3][$i]);
                    $documentName = $this->cleanString($matches[4][$i]);

                    if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate)) {
                        $addedDocuments++;
                    }
                }
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=headerColumn]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (preg_match("/estimated cost/i", $headerText) === 0) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return $this->saveEstimatedCost($da, $value);
        }

        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=headerColumn]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (preg_match("/lodgement date/i", $headerText) === 0) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            $date = \DateTime::createFromFormat("j/m/Y", $value);
            return $this->saveLodgeDate($da, $date);
        }

        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;

        $tableHeaderElements = $html->find("th[scope=col]");
        foreach ($tableHeaderElements as $tableHeaderElement) {

            $tableHeaderText = $this->cleanString($tableHeaderElement->innertext());
            if (strpos(strtolower($tableHeaderText), "name") === false) {
                continue;
            }

            $nextTableHeaderElement = $tableHeaderElement->next_sibling();
            if ($nextTableHeaderElement === null) {
                continue;
            }

            $nextTableHeaderText = $this->cleanString($nextTableHeaderElement->innertext());
            if (strpos(strtolower($nextTableHeaderText), "association") === false) {
                continue;
            }

            $headerParentElement = $tableHeaderElement->parent();
            if ($headerParentElement === null) {
                continue;
            }

            $valueRowElement = $headerParentElement->next_sibling();
            if ($valueRowElement === null) {
                continue;
            }

            $nameElement = $valueRowElement->children(0);
            if ($nameElement === null) {
                continue;
            }

            $role = "";
            $name = $this->cleanString($nameElement->innertext());

            $roleElement = $valueRowElement->children(1);
            if ($roleElement !== null) {
                $role = $this->cleanString($roleElement->innertext());
            }

            if ($this->saveParty($da, $role, $name)) {
                $addedPeople++;
            }
        }

        return ($addedPeople > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

}
