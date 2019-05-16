<?php

use Aiden\Models\Das;

class CamdenTask extends _BaseTask {

    public $council_name = "Camden";
    public $council_website_url = "https://camden.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

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

        // How many DAs do we want?
        $length = isset($params[1]) ? intval($params[1]) : 100;

        $jsonPayload = json_encode([
            'ApplicationNumber' => null,
            'ApplicationYear' => null,
            'DateFrom' => $dateStart->format("d/m/Y"),
            'DateTo' => $dateEnd->format("d/m/Y"),
            'DateType' => "1",
            'RemoveUndeterminedApplications' => false,
            'ApplicationDescription' => null,
            'ApplicationType' => null,
            'UnitNumberFrom' => null,
            'UnitNumberTo' => null,
            'StreetNumberFrom' => null,
            'StreetNumberTo' => null,
            'StreetName' => null,
            'SuburbName' => null,
            'PostCode' => null,
            'PropertyName' => null,
            'LotNumber' => null,
            'PlanNumber' => null,
            'ShowOutstandingApplications' => false,
            'ShowExhibitedApplications' => false,
            'PropertyKeys' => null,
            'PrecinctValue' => null,
            'IncludeDomains' => false
        ]);

        $postFields = http_build_query([
            'draw' => 1,
            'columns[0][data]' => 0,
            'columns[0][name]' => null,
            'columns[0][searchable]' => true,
            'columns[0][orderable]' => false,
            'columns[0][search][value]' => null,
            'columns[0][search][regex]' => false,
            'columns[1][data]' => 1,
            'columns[1][name]' => null,
            'columns[1][searchable]' => true,
            'columns[1][orderable]' => false,
            'columns[1][search][value]' => null,
            'columns[1][search][regex]' => false,
            'columns[2][data]' => 2,
            'columns[2][name]' => null,
            'columns[2][searchable]' => true,
            'columns[2][orderable]' => false,
            'columns[2][search][value]' => null,
            'columns[2][search][regex]' => false,
            'columns[3][data]' => 3,
            'columns[3][name]' => null,
            'columns[3][searchable]' => true,
            'columns[3][orderable]' => false,
            'columns[3][search][value]' => null,
            'columns[3][search][regex]' => false,
            'columns[4][data]' => 4,
            'columns[4][name]' => null,
            'columns[4][searchable]' => true,
            'columns[4][orderable]' => false,
            'columns[4][search][value]' => null,
            'columns[4][search][regex]' => false,
            'start' => 0,
            'length' => $length,
            'search[value]' => null,
            'search[regex]' => false,
            'json' => $jsonPayload
        ]);

        $requestHeaders = [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Content-Length: ' . strlen($postFields),
        ];

        $url = 'https://planning.camden.nsw.gov.au/Application/GetApplications';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
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

        $data = json_decode($output);
        if (json_last_error() !== JSON_ERROR_NONE) {

            $logMsg = "Could not parse JSON";
            $this->logger->info($logMsg);
            return false;
        }

        foreach ($data->data as $potentialDa) {

            $daCouncilUrl = "https://planning.camden.nsw.gov.au/Application/ApplicationDetails/" . $potentialDa[0];
            $this->logger->info('---------------------- URL -------------------------');
            $this->logger->info($daCouncilUrl);
            $daCouncilReference = $this->cleanString($potentialDa[1]);
            $daCouncilReferenceAlt = $this->cleanString($potentialDa[0]);

            $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilUrl($daCouncilUrl);
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilReferenceAlt($daCouncilReferenceAlt);
            $this->saveDa($da);
        }

        $this->getCouncil()->setLastScrape(new \DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $logMsg = "Done.";
        $this->logger->info($logMsg);

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;

        $propertyListElement = $html->find("[id=property-list]", 0);
        if ($propertyListElement === null) {
            return false;
        }

        $addressesString = $propertyListElement->innertext();
        $addressesArray = explode("<br/>", $addressesString);

        foreach ($addressesArray as $address) {

            $address = $this->cleanString($address);
            if ($this->saveAddress($da, $address)) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $descriptionCellElement = $html->find("td[id=description]", 0);
        if ($descriptionCellElement) {

            $newDescription = $this->cleanString(strip_tags($descriptionCellElement->innertext()));
            return $this->saveDescription($da, $newDescription);
        }

        return false;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {


        $estimatedCostHeaderElement = $html->find("#estimatedCost", 0);

        if ($estimatedCostHeaderElement === null) {
            return false;
        }

        $divElement = $estimatedCostHeaderElement->next_sibling();
        if($divElement == null){
            return false;
        }
//
//        $tableElement = $estimatedCostHeaderElement->next_sibling();
//        if ($tableElement === null) {
//            return false;
//        }
//
//        $tbodyElement = $tableElement->children(0);
//        if ($tbodyElement === null) {
//            return false;
//        }
//
//        $tableRowElement = $tbodyElement->children(0);
//        if ($tableRowElement !== null) {
//            return false;
//        }
//
//        $cellElement = $tableRowElement->children(0);
//        if ($cellElement !== null) {
//            return false;
//        }

        $estimatedCostValue = $this->cleanString($divElement->innertext());
        return $this->saveEstimatedCost($da, $estimatedCostValue);

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;

        $peopleHeaderElement = $html->find("h3[id=people]", 0);
        if ($peopleHeaderElement === null) {
            return false;
        }

        $peopleElement = $peopleHeaderElement->next_sibling();
        if ($peopleElement === null) {
            return false;
        }

        $tableElement = $peopleElement->children(0);
        if ($tableElement === null) {
            return false;
        }

        $tbodyElement = $tableElement->children(0);
        if ($tbodyElement === null) {
            return false;
        }

        foreach ($tbodyElement->children() as $tableRowElement) {

            $cellElement = $tableRowElement->children(0);
            $rowParts = explode(":", $cellElement->innertext());

            if (count($rowParts) === 2) {

                $role = $this->cleanString($rowParts[0]);
                $name = $this->cleanString($rowParts[1]);

                if ($this->saveParty($da, $role, $name) === true) {
                    $addedPeople++;
                }
            }
        }

        return ($addedPeople > 0);

    }

    protected function extractOfficers($html, $da, $params = null): bool {

        $addedOfficers = 0;

        $officerHeaderElement = $html->find("h3[id=officer]", 0);
        if ($officerHeaderElement === null) {
            return false;
        }

        $officerElement = $officerHeaderElement->next_sibling();
        if ($officerElement === null) {
            return false;
        }

        $tableElement = $officerElement->children(0);
        if ($tableElement === null) {
            return false;
        }

        $tbodyElement = $tableElement->children(0);
        if ($tbodyElement === null) {
            return false;
        }

        foreach ($tbodyElement->children() as $tableRowElement) {

            $cellElement = $tableRowElement->children(0);

            $role = "Officer";
            $name = $this->cleanString($cellElement->innertext());

            if ($this->saveParty($da, $role, $name)) {
                $addedOfficers++;
            }
        }

        return ($addedOfficers > 0);

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;

        $tableElement = $html->find("table[id=doc-table]", 0);
        if ($tableElement === null) {
            return false;
        }

        $tbodyElement = $tableElement->children(1);
        if ($tbodyElement === null) {
            return false;
        }

        foreach ($tbodyElement->children() as $tableRowElement) {

            $documentNameElement = $tableRowElement->children(1);
            if ($documentNameElement === null) {
                continue;
            }

            $documentName = $this->cleanString($documentNameElement->innertext());

            // URL
            $anchorElement = $tableRowElement->children(4)->children(0);
            $documentUrl = $this->cleanString($anchorElement->href);

            if ($this->saveDocument($da, $documentName, $documentUrl)) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $tdElements = $html->find("td");
        foreach ($tdElements as $tdElement) {

            $tdText = $this->cleanString($tdElement->innertext());
            if (strpos(strtolower($tdText), "submitted date") === false) {
                continue;
            }

            $valueElement = $tdElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            $date = \DateTime::createFromFormat("d/m/Y", $value);
            return ($this->saveLodgeDate($da, $date));
        }

        return false;

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

}
