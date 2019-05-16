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

        $logMsg = "URL: " . $url . "\r\n";
        $this->logger->info($logMsg);

        $currentPage = 1;
        $haveNotSeenResultsCount = 0;
        $lastPageHash = null;

        $formData = $this->getAspFormDataByUrl($url);
        while (true) {

            if ($haveNotSeenResultsCount > 5) {
                break;
            }

            $logMsg = "Checking page " . $currentPage . " for development applications...\r\n";
            $this->logger->info($logMsg);

            // Add page parameter to form data
            $formData["__EVENTARGUMENT"] = "Page$" . $currentPage;
            if ($currentPage > 1) {
                $formData["__EVENTTARGET"] = 'ctl00$Content$cusResultsGrid$repWebGrid$ctl00$grdWebGridTabularView';
            }
            $formData = http_build_query($formData);

            $requestHeaders = [
                "Accept: */*; q=0.8",
                "Accept-Encoding: none",
                "Accept-Language: en-GB,en-US;q=0.9,en;q=0.8",
                "Content-Length: " . strlen($formData),
                "Content-Type: application/x-www-form-urlencoded",
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
            curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

            $output = curl_exec($ch);
            $errno = curl_errno($ch);
            $errmsg = curl_error($ch);

            curl_close($ch);

            if ($errno !== 0) {

                if ($currentPage > 1) {
                    $logMsg = "cURL error: " . $errmsg . " (" . $errno . "), we've reached the end.";
                    $this->logger->info($logMsg);
                    break;
                }
                else {

                    $logMsg = "cURL error: " . $errmsg . " (" . $errno . ")";
                    $this->logger->info($logMsg);
                    return false;
                }
            }

            // Remember ASP vars for next request
            $formData = $this->getAspFormDataByString($output);

            // Hash the page output so we can check if it changed.
            $pageHash = md5($output);
            if ($lastPageHash === $pageHash) {
                $logMsg = "Hash " . $lastPageHash . "(" . ($currentPage - 1) . ") equals " . $pageHash . "(" . $currentPage . ")";
                $this->logger->info($logMsg);
                //break;
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

            $resultElements = $tableElement->children();
            $seenResults = false;
            foreach ($resultElements as $resultElement) {

                // Skip header rows etc.
                if ($resultElement->class !== "normalRow" && $resultElement->class !== "alternateRow") {
                    continue;
                }

                $seenResults = true;

                // Council Reference
                $regexPattern = '/\\\\">(.+)<\/a>\'\);/';
                if (preg_match($regexPattern, $resultElement->children(0)->innertext(), $matches) === 1) {
                    $daCouncilReference = $this->cleanString($matches[1]);
                }
                else {

                    $logMsg = "Could not find council reference";
                    $this->logger->info($logMsg);
                    continue;
                }

                $daCouncilUrl = 'https://eservices.canadabay.nsw.gov.au/eProperty/P1/eTrack/eTrackApplicationDetails.aspx'
                        . '?r=P1.WEBGUEST'
                        . '&f=%24P1.ETR.APPDET.VIW'
                        . '&ApplicationId=' . urlencode($daCouncilReference);

                $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                $da->setCouncilId($this->getCouncil()->getId());
                $da->setCouncilUrl($daCouncilUrl);
                $da->setCouncilReference($daCouncilReference);
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
        $logMsg = "Done.";
        $this->logger->info($logMsg);

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;

        $headerColumnElements = $html->find("[class=headerColumn]");
        foreach ($headerColumnElements as $i => $headerColumnElement) {

            $headerText = $this->cleanString($headerColumnElement->innertext());
            if (strpos(strtolower($headerText), "address") === false) {
                continue;
            }

            // Row containing the address
            $tableRowElement = $headerColumnElement->parent();
            if ($tableRowElement === null) {
                continue;
            }

            // Row containing the cell containing Land Description value.
            $valueRowElement = $tableRowElement->next_sibling();
            if ($valueRowElement === null) {
                continue;
            }

            // Cell containing Land Description value
            $landDescriptionElement = $valueRowElement->children(1);

            // Element containing the noscript element (headache)
            $addressElement = $headerColumnElement->next_sibling();
            if ($addressElement === null) {
                continue;
            }

            $regexPattern = '/value="(.+?)"/';
            if (preg_match($regexPattern, $addressElement->innertext(), $matches) === 0) {
                continue;
            }


            // Combine address and land description
            $address = $this->cleanString($matches[1]);
            if ($landDescriptionElement !== null) {
                $address .= ", " . $this->cleanString($landDescriptionElement->innertext());
            }

            $address = rtrim($address, ", ");
            if (strlen($address) === 0) {
                continue;
            }

            if ($this->saveAddress($da, $address) === true) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedParties = 0;

        $headerRows = $html->find("tr[class=headerRow]");
        foreach ($headerRows as $headerRow) {

            $firstChild = $headerRow->children(0);
            $secondChild = $headerRow->children(1);
            if ($firstChild === null || $secondChild === null) {
                continue;
            }

            $firstChildText = $this->cleanString($firstChild->innertext());
            $secondChildText = $this->cleanString($secondChild->innertext());
            if ($firstChildText !== "Name" && $secondChildText !== "Association") {
                continue;
            }

            // Keep looping through people table until tag is no longer <tr>
            $headerRowSibling = $headerRow->next_sibling();
            if ($headerRowSibling === null) {
                continue;
            }

            $allowedClasses = ["normalRow", "alternateRow"];
            while ($headerRowSibling->tag === "tr" && in_array($headerRowSibling->class, $allowedClasses)) {

                $nameElement = $headerRowSibling->children(0);
                $roleElement = $headerRowSibling->children(1);
                if ($nameElement === null || $roleElement === null) {
                    continue;
                }

                $name = $this->cleanString($nameElement->innertext());
                $role = $this->cleanString($roleElement->innertext());
                if (strlen($name) === 0) {
                    continue;
                }

                if ($this->saveParty($da, $role, $name)) {
                    $addedParties++;
                }

                $headerRowSibling = $headerRowSibling->next_sibling();
                if ($headerRowSibling === null) {
                    break;
                }
            }
        }

        return ($addedParties > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $headerColumnElements = $html->find("[class=headerColumn]");
        foreach ($headerColumnElements as $headerColumnElement) {

            $headerText = $headerColumnElement->innertext();
            if (strpos(strtolower($headerText), "description") === false) {
                continue;
            }

            $valueElement = $headerColumnElement->next_sibling();
            if ($valueElement === null) {
                return false;
            }

            $value = $this->cleanString($valueElement->innertext());
            if (strlen($value) > 0) {
                return $this->saveDescription($da, $value);
            }
        }

        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $headerColumnElements = $html->find("[class=headerColumn]");
        foreach ($headerColumnElements as $headerColumnElement) {

            $headerText = $headerColumnElement->innertext();
            if (strpos(strtolower($headerText), "lodgement") === false) {
                continue;
            }

            $valueElement = $headerColumnElement->next_sibling();
            if ($valueElement === null) {
                return false;
            }

            $value = $this->cleanString($valueElement->innertext());
            $date = \DateTime::createFromFormat("d/m/Y", $value);

            return $this->saveLodgeDate($da, $date);
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
        return false;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {
        return false;

    }

}
