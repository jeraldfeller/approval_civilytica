<?php

use Aiden\Models\Das;

class ParramattaTask extends _BaseTask {

    public $council_name = "Parramatta";

    public $council_website_url = "https://www.cityofparramatta.nsw.gov.au";

    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];

    public $council_default_param = "thismonth";

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        // From https://github.com/planningalerts-scrapers/parramatta/blob/master/scraper.rb
        /*
          # meaning of t parameter
          # %23427 - Development Applications
          # %23437 - Constuction Certificates
          # %23434,%23435 - Complying Development Certificates
          # %23475 - Building Certificates
          # %23440 - Tree Applications
         */
        $url = "http://eplanning.parracity.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx"
                . "?d=" . $params[0]
                . "&t=%23437,%23437,%23434,%23435,%23475,%23440";

        $this->logger->info($url);

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

        $resultElements = $html->find("div[class=result]");
        foreach ($resultElements as $resultElement) {

            // Council Reference + Description + Location
            $search = $resultElement->find('.search', 0);
            if($search){
                $councilReference = $search->plaintext;
                $url = str_replace('../../', '', $search->getAttribute('href'));
                $daCouncilUrl = "http://eplanning.parracity.nsw.gov.au/".$url;
                $daCouncilReference = $this->cleanString($councilReference);
                $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                $da->setCouncilId($this->getCouncil()->getId());
                $da->setCouncilReference($daCouncilReference);
                $da->setCouncilUrl($daCouncilUrl);
                $this->saveDa($da);
            }else{
                continue;
            }

        }

        $this->getCouncil()->setLastScrape(new \DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $addressElements = $html->find("a[title=Click to display property details]");

        foreach ($addressElements as $addressElement) {

            $daAddress = $this->cleanString($addressElement->innertext());
            if ($this->saveAddress($da, $daAddress) === true) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $detailsElement = $html->find("div[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $content = $this->cleanString($detailsElement->innertext());
        $regexPattern = '/\s*(.+?)\s*?Status:/';

        if (preg_match($regexPattern, $content, $matches) === 0) {
            return false;
        }

        return $this->saveDescription($da, $this->cleanString($matches[1]));

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $documentContainerElement = $html->find("[id=b_ctl00_ctMain_info_docs]", 0);

        if ($documentContainerElement === null) {
            return false;
        }

        $docsHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($documentContainerElement->innertext());
        if ($docsHtml === false) {
            return false;
        }

        $anchorElements = $docsHtml->find("a");
        foreach ($anchorElements as $anchorElement) {

            $regexPattern = '/Common\/Output\/Document\.aspx\?id=/';
            if (preg_match($regexPattern, $anchorElement->href) === 0) {
                continue;
            }

            $documentUrl = str_replace("../../", "/", $anchorElement->href);
            $documentUrl = "http://eplanning.parracity.nsw.gov.au" . $documentUrl;

            $parentElement = $anchorElement->parent();
            if ($parentElement === null) {
                continue;
            }

            $documentNameElement = $parentElement->next_sibling();
            if ($documentNameElement === null) {
                continue;
            }

            $documentName = $this->cleanString($documentNameElement->innertext());

            $documentDateElement = $documentNameElement->next_sibling();
            if ($documentDateElement !== null) {

                $documentDateString = $this->cleanString($documentDateElement->innertext());
                $documentDate = \DateTime::createFromFormat("d/m/Y", $documentDateString);
            }

            if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate)) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $detailsElement = $html->find("div[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $content = $this->cleanString($detailsElement->innertext());
        $regexPattern = '/Estimated Cost of Work:\s+\$ (.+)\s/';

        if (preg_match($regexPattern, $content, $matches) === 0) {
            return false;
        }

        return $this->saveEstimatedCost($da, $matches[1]);

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $detailsElement = $html->find("div[id=b_ctl00_ctMain_info_app]", 0);

        if ($detailsElement === null) {
            $this->logger->warning("Could not find details element. Lodge date may be incorrect.");
        }
        else {

            $content = $this->cleanString($detailsElement->innertext());
            $regexPattern = '/Lodged: ([0-9]{2}\/[0-9]{2}\/[0-9]{4})/';

            if (preg_match($regexPattern, $content, $matches) === 0) {
                $logMsg = "Could not find lodge date. Lodge date may be incorrect.";
                $this->logger->info($logMsg);
            }
            else {
                $oldLodgeDate = $da->getLodgeDate();
                $newLodgeDate = \DateTime::createFromFormat("d/m/Y", $matches[1]);
                return $this->saveLodgeDate($da, $newLodgeDate);
            }
        }

        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($detailsElement->innertext());
        $detailsArray = explode("<br />", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Officer: (.+)/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {

                $role = "Officer";
                $name = $this->cleanString($matches[1]);
                return $this->saveParty($da, $role, $name);
            }
        }

        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;

        $peopleContainerElement = $html->find("div[id=b_ctl00_ctMain_info_party]", 0);
        if ($peopleContainerElement === null) {
            return false;
        }

        $peopleElement = $peopleContainerElement->children(1);
        if ($peopleElement === null) {
            return false;
        }

        $rolesAndPersonsString = $this->cleanString($peopleElement->innertext());
        $rolesAndPersonsArray = explode("<br />", $rolesAndPersonsString);

        foreach ($rolesAndPersonsArray as $roleAndPersonString) {

            $roleAndPersonArray = explode("-", $roleAndPersonString);
            if (!isset($roleAndPersonArray[1])) {
                continue;
            }

            $role = $this->cleanString($roleAndPersonArray[0]);
            $name = $this->cleanString($roleAndPersonArray[1]);

            if (strlen($name) > 0) {

                if ($this->saveParty($da, $role, $name) === true) {
                    $addedPeople++;
                }
            }
        }

        return false;

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

}
