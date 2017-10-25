<?php

namespace FormTools\Modules\SwiftMailer;

use FormTools\Core;
use FormTools\Hooks;
use FormTools\Module as FormToolsModule;
use FormTools\Modules;
use PDO, PDOException;


class Module extends FormToolsModule
{
    protected $moduleName = "Swift Mailer";
    protected $moduleDesc = "This module lets your configure your server's SMTP settings for Swift Mailer, letting you override the default mail() functionality used to sent emails.";
    protected $author = "Ben Keen";
    protected $authorEmail = "ben.keen@gmail.com";
    protected $authorLink = "https://formtools.org";
    protected $version = "2.0.0";
    protected $date = "2017-10-24";
    protected $originLanguage = "en_us";
    protected $jsFiles = array(
        "{MODULEROOT}/scripts/field_options.js"
    );

    protected $nav = array(
        "module_name" => array("index.php", false),
        "word_help"   => array("help.php", true)
    );

    public function install ($module_id)
    {
        $db = Core::$db;

        $settings = array(
            array('swiftmailer_enabled', 'no'),
            array('smtp_server', ''),
            array('port', ''),
            array('requires_authentication', 'no'),
            array('username', ''),
            array('password', ''),
            array('authentication_procedure', ''),
            array('use_encryption', ''),
            array('encryption_type', ''),
            array('charset', 'UTF-8'),
            array('server_connection_timeout', 15),
            array('use_anti_flooding', ''),
            array('anti_flooding_email_batch_size', ''),
            array('anti_flooding_email_batch_wait_time', '')
        );

        $settings_query = "
            INSERT INTO {PREFIX}settings (setting_name, setting_value, module)
            VALUES (:setting_name, :setting_value, 'swift_mailer')
        ";

        try {
            $db->beginTransaction();

            foreach ($settings as $row) {
                $db->query($settings_query);
                $db->bind("setting_name", $row[0]);
                $db->bind("setting_value", $row[1]);
                $db->execute();
            }

            $db->query("
                CREATE TABLE {PREFIX}module_swift_mailer_email_template_fields (
                    email_template_id MEDIUMINT NOT NULL,
                    return_path VARCHAR(255) NOT NULL,
                    PRIMARY KEY (email_template_id)
            )");
            $db->execute();

            Hooks::registerHook("template", "swift_mailer", "edit_template_tab2", "", "swift_display_extra_fields_tab2");
            Hooks::registerHook("code", "swift_mailer", "end", "ft_create_blank_email_template", "swift_map_email_template_field");
            Hooks::registerHook("code", "swift_mailer", "end", "ft_delete_email_template", "swift_delete_email_template_field");
            Hooks::registerHook("code", "swift_mailer", "end", "ft_update_email_template", "swift_update_email_template_append_extra_fields");
            Hooks::registerHook("code", "swift_mailer", "end", "ft_get_email_template", "swift_get_email_template_append_extra_fields");

            // now map all the email template IDs for the extra return path field
            $db->query("SELECT email_id FROM {PREFIX}email_templates");
            $db->execute();
            $email_template_ids = $db->fetchAll(PDO::FETCH_COLUMN);
            foreach ($email_template_ids as $email_template_id) {
                $db->query("
                    INSERT INTO {PREFIX}module_swift_mailer_email_template_fields (email_template_id, return_path)
                    VALUE (:email_template_id, '')
                ");
                $db->bind("email_template_id", $email_template_id);
                $db->execute();
            }
            $db->processTransaction();

        } catch (PDOException $e) {
            $db->rollbackTransaction();
            return array(false, $e->getMessage());
        }

        return array(true, "");
    }



    /**
     * The Swift Mailer uninstall script. This is called by Form Tools when the user explicitly chooses to
     * uninstall the module. The hooks are automatically removed by the core script; settings needs to be explicitly
     * removed, since it's possible some modules would want to leave settings there in case they re-install it
     * later.
     */
    public function uninstall($module_id)
    {
        $db = Core::$db;

        $db->query("DROP TABLE {PREFIX}module_swift_mailer_email_template_fields");
        $db->query("DELETE FROM {PREFIX}settings WHERE module = 'swift_mailer'");

        return array(true, "");
    }


    /**
     * Updates the Swift Mailer settings.
     *
     * @param array $info
     * @return array [0] T/F<br />
     *               [1] Success / error message
     */
    public function updateSettings($info)
    {
        $L = $this->getLangStrings();

        $settings = array(
            "swiftmailer_enabled"     => (isset($info["swiftmailer_enabled"]) ? "yes" : "no"),
            "requires_authentication" => (isset($info["requires_authentication"]) ? "yes" : "no"),
            "use_encryption"          => (isset($info["use_encryption"]) ? "yes" : "no")
        );

        // Enable module
        if (isset($info["swiftmailer_enabled"])) {
            $settings["smtp_server"] = $info["smtp_server"];
            if (isset($info["port"])) {
                $settings["port"] = $info["port"];
            }
        }

        // Use authentication
        if (isset($info["requires_authentication"])) {
            if (isset($info["username"])) {
                $settings["username"] = $info["username"];
            }
            if (isset($info["password"])) {
                $settings["password"] = $info["password"];
            }
            if (isset($info["authentication_procedure"])) {
                $settings["authentication_procedure"] = $info["authentication_procedure"];
            }
        }

        // Use encryption
        if (isset($info["use_encryption"])) {
            if (isset($info["encryption_type"])) {
                $settings["encryption_type"] = $info["encryption_type"];
            }
        }

        // Advanced
        if (isset($_SESSION["ft"]["swift_mailer"]["remember_advanced_settings"]) && $_SESSION["ft"]["swift_mailer"]["remember_advanced_settings"]) {
            if (isset($info["server_connection_timeout"])) {
                $settings["server_connection_timeout"] = $info["server_connection_timeout"];
            }
            if (isset($info["charset"])) {
                $settings["charset"] = $info["charset"];
            }

            // Anti-flooding
            $settings["use_anti_flooding"] =  isset($info["use_anti_flooding"]) ? "yes" : "no";

            if (isset($info["anti_flooding_email_batch_size"])) {
                $settings["anti_flooding_email_batch_size"] = $info["anti_flooding_email_batch_size"];
            }
            if (isset($info["anti_flooding_email_batch_wait_time"])) {
                $settings["anti_flooding_email_batch_wait_time"] = $info["anti_flooding_email_batch_wait_time"];
            }
        }

        Modules::setModuleSettings($settings);

        return array(true, $L["notify_settings_updated"]);
    }


    /**
     * Called on the test tab. It sends one of the three test emails: plain text, HTML and multi-part
     * using the SMTP settings configured on the settings tab. This is NOT for the test email done on the
     * email templates "Test" tab; it uses the main swift_send_email function for that.
     *
     * @param array $info
     * @return array [0] T/F<br />
     *               [1] Success / error message
     */
//    public function sendTestEmail($info)
//    {
//        $L = $this->getLangStrings();
//        $settings = $this->getSettings();
//
//        // find out what version of PHP we're running
//        $version = phpversion();
//        $version_parts = explode(".", $version);
//        $main_version = $version_parts[0];
//        $current_folder = dirname(__FILE__);
//
//        if ($main_version == "5") {
//            $php_version_folder = "php5";
//        } else if ($main_version == "4") {
//            $php_version_folder = "php4";
//        } else {
//            return array(false, $L["notify_php_version_not_found_or_invalid"]);
//        }
//
//        require_once("$current_folder/$php_version_folder/ft_library.php");
//        require_once("$current_folder/$php_version_folder/Swift.php");
//        require_once("$current_folder/$php_version_folder/Swift/Connection/SMTP.php");
//
//
//        // if we're requiring authentication, include the appropriate authenticator file
//        if ($settings["requires_authentication"] == "yes") {
//            switch ($settings["authentication_procedure"]) {
//                case "LOGIN":
//                    require_once("$current_folder/$php_version_folder/Swift/Authenticator/LOGIN.php");
//                    break;
//                case "PLAIN":
//                    require_once("$current_folder/$php_version_folder/Swift/Authenticator/PLAIN.php");
//                    break;
//                case "CRAMMD5":
//                    require_once("$current_folder/$php_version_folder/Swift/Authenticator/CRAMMD5.php");
//                    break;
//            }
//        }
//
//        // this passes off the control flow to the swift_php_ver_send_test_email() function
//        // which is defined in both the PHP 5 and PHP 4 ft_library.php file, but only one of
//        // which was require()'d
//        return $this->sendTestEmail($settings, $info);
//    }


    /**
     * Sends an email with the Swift Mailer module.
     *
     * @param array $email_components
     * @return array
     */
//    public function sendEmail($email_components)
//    {
//        $db = Core::$db;
//        $L = $this->getLangStrings();
//        $settings = $this->getSettings();
//
//        // find out what version of PHP we're running
//        $version = phpversion();
//        $version_parts = explode(".", $version);
//        $main_version = $version_parts[0];
//
//        if ($main_version == "5") {
//            $php_version_folder = "php5";
//        } else if ($main_version == "4") {
//            $php_version_folder = "php4";
//        } else {
//            return array(false, $L["notify_php_version_not_found_or_invalid"]);
//        }
//
//        // include the main files
//        $current_folder = dirname(__FILE__);
//        require_once("$current_folder/$php_version_folder/ft_library.php");
//        require_once("$current_folder/$php_version_folder/Swift.php");
//        require_once("$current_folder/$php_version_folder/Swift/Connection/SMTP.php");
//
//        $use_anti_flooding = (isset($settings["use_anti_flooding"]) && $settings["use_anti_flooding"] == "yes");
//
//        // if the user has requested anti-flooding, include the plugin
//        if ($use_anti_flooding) {
//            require_once("$current_folder/$php_version_folder/Swift/Plugin/AntiFlood.php");
//        }
//
//        // if we're requiring authentication, include the appropriate authenticator file
//        if ($settings["requires_authentication"] == "yes") {
//            switch ($settings["authentication_procedure"]) {
//                case "LOGIN":
//                    require_once("$current_folder/$php_version_folder/Swift/Authenticator/LOGIN.php");
//                    break;
//                case "PLAIN":
//                    require_once("$current_folder/$php_version_folder/Swift/Authenticator/PLAIN.php");
//                    break;
//                case "CRAMMD5":
//                    require_once("$current_folder/$php_version_folder/Swift/Authenticator/CRAMMD5.php");
//                    break;
//            }
//        }
//
//        $success = true;
//        $message = "The email was successfully sent.";
//
//        // make the SMTP connection (this is PHP-version specific)
//        $smtp = swift_make_smtp_connection($settings);
//
//        // if required, set the server timeout (Swift Mailer default == 15 seconds)
//        if (isset($settings["server_connection_timeout"]) && !empty($settings["server_connection_timeout"])) {
//            $smtp->setTimeout($settings["server_connection_timeout"]);
//        }
//
//        if ($settings["requires_authentication"] == "yes") {
//            $smtp->setUsername($settings["username"]);
//            $smtp->setPassword($settings["password"]);
//        }

//        $swift =& new Swift($smtp);
//
//        // apply the anti-flood settings
//        if ($use_anti_flooding) {
//            $anti_flooding_email_batch_size      = $settings["anti_flooding_email_batch_size"];
//            $anti_flooding_email_batch_wait_time = $settings["anti_flooding_email_batch_wait_time"];
//
//            if (is_numeric($anti_flooding_email_batch_size) && is_numeric($anti_flooding_email_batch_wait_time)) {
//                $swift->attachPlugin(new Swift_Plugin_AntiFlood($anti_flooding_email_batch_size,
//                $anti_flooding_email_batch_wait_time), "anti-flood");
//            }
//        }
//
//        // now send the appropriate email
//        if (!empty($email_components["text_content"]) && !empty($email_components["html_content"])) {
//            $email =& new Swift_Message($email_components["subject"]);
//            $email->attach(new Swift_Message_Part($email_components["text_content"]));
//            $email->attach(new Swift_Message_Part($email_components["html_content"], "text/html"));
//
//        } else if (!empty($email_components["text_content"])) {
//            $email =& new Swift_Message($email_components["subject"]);
//            $email->attach(new Swift_Message_Part($email_components["text_content"]));
//
//        } else if (!empty($email_components["html_content"])) {
//            $email =& new Swift_Message($email_components["subject"]);
//            $email->attach(new Swift_Message_Part($email_components["html_content"], "text/html"));
//        }
//
//        // add the return path if it's defined
//        if (isset($email_components["email_id"])) {
//            $db->query("
//                SELECT return_path
//                FROM {PREFIX}module_swift_mailer_email_template_fields
//                WHERE email_template_id = :email_template_id
//            ");
//            $db->bind("email_template_id", $email_components["email_id"]);
//            $db->execute();
//
//            $return_path = $db->fetch(PDO::FETCH_COLUMN);
//            if (isset($return_path) && !empty($return_path)) {
//                $email->setReturnPath($return_path);
//            }
//        }
//
//        if (isset($settings["charset"]) && !empty($settings["charset"])) {
//            $email->setCharset($settings["charset"]);
//        }
//
//        // now compile the recipient list
//        $recipients =& new Swift_RecipientList();
//
//        foreach ($email_components["to"] as $to) {
//            if (!empty($to["name"]) && !empty($to["email"])) {
//                $recipients->addTo($to["email"], $to["name"]);
//            } else if (!empty($to["email"])) {
//                $recipients->addTo($to["email"]);
//            }
//        }
//
//        if (!empty($email_components["cc"]) && is_array($email_components["cc"])) {
//            foreach ($email_components["cc"] as $cc) {
//                if (!empty($cc["name"]) && !empty($cc["email"])) {
//                    $recipients->addCc($cc["email"], $cc["name"]);
//                } else if (!empty($cc["email"])) {
//                    $recipients->addCc($cc["email"]);
//                }
//            }
//        }
//
//        if (!empty($email_components["bcc"]) && is_array($email_components["bcc"])) {
//            foreach ($email_components["bcc"] as $bcc) {
//                if (!empty($bcc["name"]) && !empty($bcc["email"])) {
//                    $recipients->addBcc($bcc["email"], $bcc["name"]);
//                } else if (!empty($bcc["email"])) {
//                    $recipients->addBcc($bcc["email"]);
//                }
//            }
//        }
//
//        if (!empty($email_components["reply_to"]["name"]) && !empty($email_components["reply_to"]["email"])) {
//            $email->setReplyTo($email_components["reply_to"]["name"] . "<" . $email_components["reply_to"]["email"] . ">");
//        } else if (!empty($email_components["reply_to"]["email"])) {
//            $email->setReplyTo($email_components["reply_to"]["email"]);
//        }
//
//        if (!empty($email_components["from"]["name"]) && !empty($email_components["from"]["email"])) {
//            $from = new Swift_Address($email_components["from"]["email"], $email_components["from"]["name"]);
//        } else if (!empty($email_components["from"]["email"])) {
//            $from = new Swift_Address($email_components["from"]["email"]);
//        }
//
//        // finally, if there are any attachments, attach 'em
//        if (isset($email_components["attachments"])) {
//            foreach ($email_components["attachments"] as $attachment_info) {
//                $filename      = $attachment_info["filename"];
//                $file_and_path = $attachment_info["file_and_path"];
//
//                if (!empty($attachment_info["mimetype"])) {
//                    $email->attach(new Swift_Message_Attachment(new Swift_File($file_and_path), $filename, $attachment_info["mimetype"]));
//                } else {
//                    $email->attach(new Swift_Message_Attachment(new Swift_File($file_and_path), $filename));
//                }
//            }
//        }
//
//        if ($use_anti_flooding) {
//            $swift->batchSend($email, $recipients, $from);
//        } else {
//            $swift->send($email, $recipients, $from);
//        }
//
//        return array($success, $message);
//    }


    /**
     * Displays the extra fields on the Edit Email template: tab 2
     */
    public function displayExtraFieldsTab2($location, $info)
    {
        $L = $this->getLangStrings();

        $return_path = htmlspecialchars($info["template_info"]["swift_mailer_settings"]["return_path"]);

        echo <<< END
<tr>
  <td valign="top" class="red"> </td>
  <td valign="top">{$L["phrase_undeliverable_email_recipient"]}</td>
  <td valign="top">
    <input type="text" name="swift_mailer_return_path" style="width: 300px" value="$return_path" />
  </td>
</tr>
END;
    }


    /**
     * This is called by the ft_create_blank_email_template function.
     *
     * @param array $info
     */
    public function mapEmailTemplateField($info)
    {
        $db = Core::$db;

        $db->query("
            INSERT INTO {PREFIX}module_swift_mailer_email_template_fields (email_template_id, return_path)
            VALUES (:email_template_id, '')
        ");
        $db->bind("email_template_id", $info["email_id"]);
        $db->execute();
    }


    /**
     * Hook for: Emails::createBlankEmailTemplate()
     *
     * @param array $info
     */
    public function deleteEmailTemplateField($info)
    {
        $db = Core::$db;
        $db->query("
            DELETE FROM {PREFIX}module_swift_mailer_email_template_fields
            WHERE email_template_id = :email_template_id
        ");
        $db->bind("email_template_id", $info["email_id"]);
        $db->execute();
    }


    /**
     * This extends the ft_get_email_template function, adding the additional Swift Mailer return_path variable within a "swift_mailer_settings"
     * key.
     */
    public function getEmailTemplateAppendExtraFields($info)
    {
        $db = Core::$db;

        $db->query("
            SELECT return_path
            FROM {PREFIX}module_swift_mailer_email_template_fields
            WHERE email_template_id = :email_id
        ");
        $db->bind("email_id", $info["email_template"]["email_id"]);
        $db->execute();

        $info["email_template"]["swift_mailer_settings"]["return_path"] = $db->fetch(PDO::FETCH_COLUMN);

        return $info;
    }


    public function updateEmailTemplateAppendExtraFields($info)
    {
        $db = Core::$db;

        $db->query("
            UPDATE {PREFIX}module_swift_mailer_email_template_fields
            SET    return_path = :return_path
            WHERE  email_template_id = :email_template_id
        ");
        $db->bindAll(array(
        "return_path" => $info["info"]["swift_mailer_return_path"],
        "email_template_id" => $info["email_id"]
        ));
        $db->execute();
    }
}

