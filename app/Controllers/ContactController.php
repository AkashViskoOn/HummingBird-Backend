<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ContactModel;
use App\Models\UserModel;

helper('token');

class ContactController extends BaseController
{
    private ?ContactModel $contactModel = null;
    private ?UserModel $userModel = null;

    private function getContactModel(): ContactModel
    {
        if ($this->contactModel === null) {
            $this->contactModel = new ContactModel();
        }
        return $this->contactModel;
    }

    private function getUserModel(): UserModel
    {
        if ($this->userModel === null) {
            $this->userModel = new UserModel();
        }
        return $this->userModel;
    }

    public function addContact()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Authorization Failed", "contact" => null
            ]);
        }

        $user = user();
        $mobileNumber = $this->request->getPost('mobile_number') ?? '';

        $existing = $this->getContactModel()->where('c_mobile_number', $mobileNumber)->first();
        if ($existing) {
            return $this->response->setJSON([
                "status" => false, "message" => "Contact already registered", "contact" => null
            ]);
        }

        $contactData = [
            "c_unique_id" => rand(100000, 999999),
            "c_first_name" => $this->request->getPost('first_name') ?? '',
            "c_last_name" => $this->request->getPost('last_name') ?? '',
            "c_mobile_number" => $mobileNumber,
            "c_email_address" => $this->request->getPost('email_address') ?? '',
            "c_company" => $this->request->getPost('company') ?? '',
            "c_notes" => $this->request->getPost('notes') ?? '',
            "c_user_id" => $user['user_id']
        ];

        $contactId = $this->getContactModel()->insert($contactData, true);
        $contact = $this->getContactModel()->find($contactId);

        return $this->response->setJSON([
            "status" => true, "message" => "Contact Created Successfully", "contact" => $contact
        ]);
    }

    public function fetchContacts()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Authorization Failed", "contacts" => null
            ]);
        }

        $user = user();
        $contacts = $this->getContactModel()
            ->where('c_user_id', $user['user_id'])
            ->findAll();

        return $this->response->setJSON([
            "status" => true, "message" => "Contacts Fetched", "contacts" => $contacts
        ]);
    }

    public function checkUser()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Authorization Failed", "user" => null
            ]);
        }

        $mobileNumber = $this->request->getVar('mobile_number') ?? '';
        if (empty($mobileNumber)) {
            return $this->response->setJSON([
                "status" => false, "message" => "Mobile number is required", "user" => null
            ]);
        }

        $found = $this->getUserModel()
            ->where('user_mobile_num', $mobileNumber)
            ->first();

        if ($found) {
            $user = user();
            if ($found['user_id'] == $user['user_id']) {
                return $this->response->setJSON([
                    "status" => false, "message" => "This is your own number", "user" => null
                ]);
            }
            return $this->response->setJSON([
                "status" => true,
                "message" => "User found",
                "user" => public_user($found),
            ]);
        }

        return $this->response->setJSON([
            "status" => false, "message" => "User not found on Humming Bird", "user" => null
        ]);
    }

    public function updateContact()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Authorization Failed", "contact" => null
            ]);
        }

        $user = user();
        $contactId = $this->request->getPost('c_id') ?? '';

        $contact = $this->getContactModel()
            ->where('c_id', $contactId)
            ->where('c_user_id', $user['user_id'])
            ->first();

        if (!$contact) {
            return $this->response->setJSON([
                "status" => false, "message" => "Contact not found", "contact" => null
            ]);
        }

        // The mobile number and the unique id are the contact's handles on the
        // server (check-user-by-number and create-room match on them), so they are
        // deliberately NOT editable here -- silently rewriting one would orphan the
        // saved row from every direct chat it already belongs to.
        $updateData = [
            "c_first_name" => $this->request->getPost('first_name') ?? '',
            "c_last_name" => $this->request->getPost('last_name') ?? '',
            "c_email_address" => $this->request->getPost('email_address') ?? '',
            "c_company" => $this->request->getPost('company') ?? '',
            "c_notes" => $this->request->getPost('notes') ?? '',
        ];

        $this->getContactModel()->update($contact['c_id'], $updateData);
        $updated = $this->getContactModel()->find($contact['c_id']);

        return $this->response->setJSON([
            "status" => true, "message" => "Contact Updated Successfully", "contact" => $updated
        ]);
    }

    public function deleteContact()
    {
        $isToken = check_jwt_authentication();
        if (!$isToken) {
            return $this->response->setJSON([
                "status" => false, "message" => "Authorization Failed", "contact" => null
            ]);
        }

        $user = user();
        $contactId = $this->request->getPost('c_id') ?? '';

        $contact = $this->getContactModel()
            ->where('c_id', $contactId)
            ->where('c_user_id', $user['user_id'])
            ->first();

        if (!$contact) {
            return $this->response->setJSON([
                "status" => false, "message" => "Contact not found", "contact" => null
            ]);
        }

        $this->getContactModel()->delete($contact['c_id']);

        return $this->response->setJSON([
            "status" => true, "message" => "Contact Deleted Successfully", "contact" => null
        ]);
    }
}
