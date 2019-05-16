<?php

use Aiden\Models\Das;

class CanterburyTask extends _BaseTask {

    public $council_name = "Canterbury";
    public $council_website_url = "http://www.canterbury.nsw.gov.au";
    public $council_params = ["thisweek", "thismonth"];
    public $council_default_param = "thismonth";
    public $canterbury_bankstown_id = 34;


    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        switch ($params[0]) {

            case "thisweek":
                $dateStart = new DateTime("Monday this week");
                $dateEnd = new DateTime("Sunday this week");
                break;
            case "lastweek":
                $dateStart = new DateTime("Monday last week");
                $dateEnd = new DateTime("Sunday last week");
                break;
            case "thismonth":
                $dateStart = new DateTime("first day of this month");
                $dateEnd = new DateTime("last day of this month");
                break;
            case "lastmonth":
                $dateStart = new DateTime("first day of last month");
                $dateEnd = new DateTime("last day of last month");
                break;
            default:
                return false;
        }

        // Let's loop until we see development applications outside the requested dates
        $startIndex = 0;
        $errors = 0;
        while (true) {

            $url = "http://datrack.canterbury.nsw.gov.au/cgi/datrack.pl?search=search"
                    . "&sortfield=^metadata.date_lodged"
                    . "&startidx=" . $startIndex;

            $logMsg = "URL: " . $url . "";
            $this->logger->info($logMsg);
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

                $logMsg = "cURL error: " . $errmsg . " (" . $errno . ")";
                $this->logger->info($logMsg);
                $errors++;
                if ($errors > 5) {
                    return;
                }
                continue;
            }

            $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
            if (!$html) {

                $logMsg = "Could not parse HTML";
                $this->logger->info($logMsg);
                $errors++;
                if ($errors > 5) {
                    return;
                }
                continue;
            }

            $resultElements = $html->find("tr[class=datrack_resultrow_odd], tr[class=datrack_resultrow_even]");
            foreach ($resultElements as $resultElement) {

                // Council Reference + URL
                $anchorElement = $resultElement->children(1)->children(0);
                $daCouncilUrl = $this->cleanString($anchorElement->href);
                $daCouncilReference = $this->cleanString($anchorElement->innertext());

                // Description
                $daDescription = $this->cleanString($resultElement->next_sibling()->children(1)->innertext());

                // Lodge date
                $rawDate = $this->cleanString($resultElement->children(5)->innertext());
                $daLodgeDate = \DateTime::createFromFormat("d/m/Y", $rawDate);

                // Now check if the lodge date is within our requested timeframe (thisweek, lastweek etc.)
                if ($daLodgeDate >= $dateStart && $daLodgeDate < $dateEnd) {
                    // Great!
                }
                else {
                    $logMsg = "Reached the end of this time frame.";
                    $this->logger->info($logMsg);
                    break 2;
                }

                $da = Das::exists($this->canterbury_bankstown_id, $daCouncilReference) ?: new Das();
                $da->setCouncilId($this->canterbury_bankstown_id);
                $da->setCouncilReference($daCouncilReference);
                $da->setCouncilUrl($daCouncilUrl);
                $da->setDescription($daDescription);
                $da->setLodgeDate($daLodgeDate);
                $this->saveDa($da);
            }

            // Increase Start Index and pages checked
            $startIndex += 10;
        }

        $this->getCouncil()->setLastScrape(new \DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $logMsg = "Done.";
        $this->logger->info($logMsg);

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;

        $tableHeaderElements = $html->find("th");
        foreach ($tableHeaderElements as $tableHeaderElement) {

            $headerText = $this->cleanString($tableHeaderElement->innertext());
            if ($headerText !== "Property No") {
                continue;
            }

            $secondHeaderElement = $tableHeaderElement->next_sibling();
            if (!$secondHeaderElement) {
                continue;
            }

            $secondHeaderElementText = $this->cleanString($secondHeaderElement->innertext());
            if ($secondHeaderElementText !== "Address") {
                continue;
            }

            $nextTableRowElement = $tableHeaderElement->parent()->next_sibling();
            while ($nextTableRowElement !== null) {

                $daAddress = $this->cleanString($nextTableRowElement->children(1)->innertext());
                if ($this->saveAddress($da, $daAddress)) {
                    $addedAddresses++;
                }

                $nextTableRowElement = $nextTableRowElement->next_sibling();
            }

            break;
        }

        return ($addedAddresses > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $tableHeaderElements = $html->find("th");
        foreach ($tableHeaderElements as $tableHeaderElement) {

            $headerText = $this->cleanString($tableHeaderElement->innertext());
            if (strpos(strtolower($headerText), "estimated cost") !== false) {

                $valueElement = $tableHeaderElement->next_sibling();
                if (!$valueElement) {
                    continue;
                }

                return $this->saveEstimatedCost($da, $valueElement->innertext());
            }
        }

        return false;

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $tableHeaderElements = $html->find("th");
        foreach ($tableHeaderElements as $tableHeaderElement) {

            $headerText = $this->cleanString($tableHeaderElement->innertext());
            if (strpos(strtolower($headerText), "description") !== false) {

                $valueElement = $tableHeaderElement->next_sibling();
                if (!$valueElement) {
                    continue;
                }

                $oldDescription = $da->getDescription();
                $newDescription = $this->cleanString($valueElement->innertext());

                return $this->saveDescription($da, $newDescription);
            }
        }

        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedParties = 0;

        $tableHeaderElements = $html->find("th");
        foreach ($tableHeaderElements as $tableHeaderElement) {

            $headerText = $this->cleanString($tableHeaderElement->innertext());
            if (strpos(strtolower($headerText), "role") === false) {
                continue;
            }

            // Check if second header exists
            $secondHeaderElement = $tableHeaderElement->next_sibling();
            if ($secondHeaderElement === null) {
                continue;
            }

            // Check if second header has correct text
            $secondHeaderText = $this->cleanString($secondHeaderElement->innertext());
            if (strpos(strtolower($secondHeaderText), "name no") === false) {
                continue;
            }

            // Check if third header exists
            $thirdHeaderElement = $secondHeaderElement->next_sibling();
            if ($thirdHeaderElement === null) {
                continue;
            }

            // Check if third header text is correct
            $thirdHeaderText = $this->cleanString($thirdHeaderElement->innertext());
            if (strpos(strtolower($thirdHeaderText), "name") === false) {
                continue;
            }

            $nextTableRowElement = $tableHeaderElement
                    ->parent()
                    ->next_sibling();

            while ($nextTableRowElement !== null) {

                if ($nextTableRowElement->tag === "tr") {

                    $role = $this->cleanString($nextTableRowElement->children(0)->innertext());
                    $role = preg_replace('/[[:^print:]]/', '', $role);
                    if (strlen($role) === 0) {
                        $role = null;
                    }

                    $name = $this->cleanString($nextTableRowElement->children(2)->innertext());

                    if ($this->saveParty($da, $role, $name)) {
                        $addedParties++;
                    }
                }

                $nextTableRowElement = $nextTableRowElement->next_sibling();
            }
            break;
        }

        return ($addedParties > 0);

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;

        $detailHeadingElements = $html->find("span[class=wh_preview_detail_heading]");
        foreach ($detailHeadingElements as $detailHeadingElement) {

            $detailHeadingText = $this->cleanString($detailHeadingElement->innertext());
            if (strpos(strtolower($detailHeadingText), "documents") === false) {
                continue;
            }

            // Get parent TR
            $parentRowElement = $detailHeadingElement
                    ->parent() // The first parent is the <th>
                    ->parent(); // The grandparent is the <tr>

            if ($parentRowElement === null) {
                continue;
            }

            // Get sibling containing documents (if any)
            $nextDocumentRowElement = $parentRowElement
                    ->next_sibling() // The closest sibling to $parentRow contains the table headers
                    ->next_sibling();

            while ($nextDocumentRowElement !== null) {

                $anchorElement = $nextDocumentRowElement->children(0)->children(0);
                $dateElement = $nextDocumentRowElement->children(2);
                $nameElement = $nextDocumentRowElement->children(3);

                $documentUrl = $this->cleanString($anchorElement->href);
                $documentName = $this->cleanString($nameElement->innertext());
                $documentDate = \DateTime::createFromFormat("d/m/Y", $this->cleanString($dateElement->innertext()));

                if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate)) {
                    $addedDocuments++;
                }

                $nextDocumentRowElement = $nextDocumentRowElement->next_sibling();
            }

            break;
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
        return $this->saveLodgeDate($da, new \DateTime());

    }

}
