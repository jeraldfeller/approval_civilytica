<?php

use Aiden\Models\Das;

class BurwoodTask extends _BaseTask {

    public $council_name = "Burwood";

    public $council_website_url = "http://www.burwood.nsw.gov.au";

    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];

    public $council_default_param = "thismonth";

    /**
     * Request the initial page and receive a cookie so we can access the DAs
     */
    public function getUserContainer() {

        $url = "https://ecouncil.burwood.nsw.gov.au/eservice/daEnquiryInit.do?doc_typ=10&nodeNum=219";

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

            $logMsg = sprintf("cURL error: [%s] (%s)", $errmsg, $errno);
            $this->logger->info($logMsg);
            return false;
        }

        return true;

    }

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        switch ($params[0]) {

            case "thisweek":
                $dateStart = new DateTime("next Monday -1 week");
                $dateEnd = new DateTime("next Monday");
                break;
            case "lastweek":
                $dateStart = new DateTime("next Monday -2 weeks");
                $dateEnd = new DateTime("next Monday -1 week");
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

        // Request initial page to retrieve cookie so we're allowed access to DAs
        $this->getUserContainer();

        // Initial URL
        $url = "https://ecouncil.burwood.nsw.gov.au/eservice/daEnquiry.do"
                . "?number="
                . "&lodgeRangeType=on"
                . "&dateFrom=" . urlencode($dateStart->format("d/m/Y"))
                . "&dateTo=" . urlencode($dateEnd->format("d/m/Y"))
                . "&detDateFromString="
                . "&detDateToString="
                . "&streetName="
                . "&suburb=0"
                . "&unitNum="
                . "&houseNum=0%0D%0A%09%09%09%09%09"
                . "&planNumber="
                . "&strataPlan="
                . "&lotNumber="
                . "&propertyName="
                . "&searchMode=A"
                . "&submitButton=Search";

        echo $url . "\r\n";

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

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        $daIndex = 0;

        $resultElements = $html->find("div");
        foreach ($resultElements as $resultElement) {

            $headerElement = $resultElement->prev_sibling();
            if (!$headerElement || $headerElement->tag !== "h4") {
                continue;
            }

            foreach ($resultElement->children() as $rowDataElement) {

                if ($rowDataElement->class !== "rowDataOnly") {
                    continue;
                }

                $key = $this->cleanString($rowDataElement->children(0)->innertext());
                $value = $this->cleanString($rowDataElement->children(1)->innertext());

                if ($key === "Application No.") {

                    $daCouncilReference = $value;
                    $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                    $da->setCouncilId($this->getCouncil()->getId());
                    $da->setCouncilReference($daCouncilReference);
                }
            }

            if ($this->saveDa($da)) {

                /**
                 * This council's website sets DA-specific URLs in memory, so we'll have to visit them right away.
                 * e.g. https://ecouncil.burwood.nsw.gov.au/eservice/daEnquiryDetails.do?index=0
                 * would refer to a different DA for two separate sessions, so we have to scrape meta in the same session
                 */
                $this->scrapeMeta($da, $daIndex);
            }

            $this->logger->info("");
            $daIndex++;
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $logMsg = "Done.";
        $this->logger->info($logMsg);

    }

    public function scrapeMeta($da, $daIndex, $params = null) {

        // Weird Phalcon bug doesn't allow us to save model twice without errors.
        $oldDaId = $da->getId();
        unset($da);
        $da = Das::findFirstById($oldDaId);
        if ($da === false) {

            $this->logger->critical("Could not find related development application in scrapeMeta()-method.");
            return false;
        }

        $url = "https://ecouncil.burwood.nsw.gov.au/eservice/daEnquiryDetails.do?index=" . $daIndex;

        $this->logger->info("Scraping meta development application [{da_id}]...", ["da_id" => $da->getId()]);

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

            $logMsg = sprintf("cURL error: [%s] (%s)", $errmsg, $errno);
            $this->logger->info($logMsg);
            return false;
        }

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {

            $logMsg = "Could not parse HTML";
            $this->logger->info($logMsg);
            return false;
        }

        // Extract addresses is a core method, without it don't run.
        if (method_exists($this, "extractAddresses")) {
            $extractedAddresses = $this->extractAddresses($html, $da);
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
            $this->logger->info("Something went wrong when trying to update crawl status ({error})", ["error" => print_r($da->getMessages(), true)]);
        }

    }

    public function scrapeMetaAction() {

        $this->logger->warning("This method cannot be run independently from the scrape()-method. Stopping execution.");
        return false;

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $keyElements = $html->find("span[class=key]");
        foreach ($keyElements as $keyElement) {

            $keyText = $this->cleanString($keyElement->innertext());
            if (strpos(strtolower($keyText), "type of work") === false) {
                continue;
            }

            $valueElement = $keyElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return (strlen($value) > 0 && $this->saveDescription($da, $value));
        }

        return false;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $keyElements = $html->find("span[class=key]");
        foreach ($keyElements as $keyElement) {

            $keyText = $this->cleanString($keyElement->innertext());
            if (strpos(strtolower($keyText), "cost of work") === false) {
                continue;
            }

            $valueElement = $keyElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return $this->saveEstimatedCost($da, $value);
        }

        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $keyElements = $html->find("span[class=key]");
        foreach ($keyElements as $keyElement) {

            $keyText = $this->cleanString($keyElement->innertext());
            if (strpos(strtolower($keyText), "lodged") === false) {
                continue;
            }

            $valueElement = $keyElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            $date = \DateTime::createFromFormat("d/m/Y", $value);
            return $this->saveLodgeDate($da, $date);
        }

        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;
        $possibleRoles = ["officer", "applicant", "certifier"];
        $keyElements = $html->find("span[class=key]");

        foreach ($keyElements as $keyElement) {

            foreach ($possibleRoles as $possibleRole) {

                $keyText = $this->cleanString($keyElement->innertext());
                if (strpos(strtolower($keyText), $possibleRole) === false) {
                    continue;
                }

                $valueElement = $keyElement->next_sibling();
                if ($valueElement === null) {
                    continue;
                }

                $value = $this->cleanString($valueElement->innertext());
                if ($this->saveParty($da, $keyText, $value)) {
                    $addedPeople++;
                }
            }
        }

        return ($addedPeople > 0);

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $keyElements = $html->find("span[class=key]");
        foreach ($keyElements as $keyElement) {

            $keyText = $this->cleanString($keyElement->innertext());
            if (strpos(strtolower($keyText), "property details") === false) {
                continue;
            }

            $valueElement = $keyElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return (strlen($value) > 0 && $this->saveAddress($da, $value));
        }

        return false;

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {
        return false;

    }

}
