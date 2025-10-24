<?php
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

use WHMCS\Database\Capsule;

function tcknverifier_config()
{
    return [
        'name'        => 'TCKN Verifier',
        'description' => 'Validates Turkish Identity Number on signup/profile, enforces uniqueness & immutability, and shows a UI badge.',
        'author'      => 'Hedef Global Bilişim',
        'language'    => 'Turkish',
        'version'     => '1.0.0',
        'fields'      => [
            'FieldId' => [
                'FriendlyName' => 'Custom Field ID',
                'Type'         => 'text',
                'Size'         => '6',
                'Description'  => 'Client custom field ID for TCKN. Leave empty to auto-detect/create by name.',
            ],
            'FieldName' => [
                'FriendlyName' => 'Custom Field Name',
                'Type'         => 'text',
                'Size'         => '64',
                'Default'      => 'TC Kimlik No',
                'Description'  => 'Used if FieldId is empty. Your field might be named e.g. "T.C Kimlik No".',
            ],
            'EnforceUnique' => [
                'FriendlyName' => 'Enforce Uniqueness',
                'Type'         => 'yesno',
                'Default'      => 'on',
            ],
            'ImmutableAfterCreate' => [
                'FriendlyName' => 'Immutable After Create',
                'Type'         => 'yesno',
                'Default'      => 'on',
            ],
            'AllowClientEdit' => [
                'FriendlyName' => 'Allow Client Edit',
                'Type'         => 'yesno',
                'Default'      => '',
            ],
            'AllowAdminEdit' => [
                'FriendlyName' => 'Allow Admin Edit',
                'Type'         => 'yesno',
                'Default'      => 'on',
            ],
            'EnableUiBadge' => [
                'FriendlyName' => 'Show "Registered" Badge',
                'Type'         => 'yesno',
                'Default'      => 'on',
            ],
            'DebugLog' => [
                'FriendlyName' => 'Debug Log Activity',
                'Type'         => 'yesno',
                'Default'      => '',
            ],
        ],
    ];
}

function tcknverifier_activate()
{
    try {
        // Try to detect existing field by common names
        $candidateNames = ['TC Kimlik No', 'T.C Kimlik No', 'T.C. Kimlik No', 'TCKN', 'T.C No'];
        $fieldId = null;
        foreach ($candidateNames as $name) {
            $id = Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->where('fieldname', $name)
                ->value('id');
            if ($id) { $fieldId = (int)$id; break; }
        }
        if (!$fieldId) {
            // Create default named field if not found
            $fieldId = Capsule::table('tblcustomfields')->insertGetId([
                'type'        => 'client',
                'relid'       => 0,
                'fieldname'   => 'TC Kimlik No',
                'fieldtype'   => 'text',
                'description' => 'Turkish Identity Number (TCKN)',
                'required'    => 1,
                'showorder'   => 1,
                'adminonly'   => 0,
                'fieldoptions'=> '',
                'sortorder'   => 0,
            ]);
        }

        // Persist FieldId into addon settings
        Capsule::table('tbladdonmodules')->updateOrInsert(
            ['module' => 'tcknverifier', 'setting' => 'FieldId'],
            ['value'  => (string)$fieldId]
        );

        return ['status' => 'success', 'description' => 'Activated. Custom field ID: ' . (int)$fieldId];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function tcknverifier_deactivate()
{
    return ['status' => 'success', 'description' => 'Module deactivated'];
}

function tcknverifier_upgrade($vars) { /* migrations if needed */ }
