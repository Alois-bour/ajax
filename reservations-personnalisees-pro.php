<?php
/**
 * Plugin Name: Booking Milipol 2025
 * Description: Booking Milipol 2025
 * Version: 9.3
 * Author: Aloïs BOUR - TRACIP
 * Text Domain: Booking-Milipol-2025
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ReservationsPlugin
{
    private static $instance = null;

    private $table_reservations;
    private $table_blocked_slots;
    private $table_subjects;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;
        $this->table_reservations = $wpdb->prefix . 'reservations_plugin';
        $this->table_blocked_slots = $wpdb->prefix . 'reservations_blocked_slots';
        $this->table_subjects = $wpdb->prefix . 'reservations_subjects';

        $this->init_hooks();
    }

    private function init_hooks()
    {
        register_activation_hook(__FILE__, [$this, 'install_database']);

        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'handle_rewrite_request']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);

        add_shortcode('reservation_form', [$this, 'render_form']);

        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Form handlers
        add_action('admin_post_reservations_export', [$this, 'handle_csv_export']);
        add_action('admin_post_reservations_add_blocked_slot', [$this, 'handle_add_blocked_slot']);
        add_action('admin_post_reservations_delete_blocked_slot', [$this, 'handle_delete_blocked_slot']);
        add_action('admin_post_reservations_add_subject', [$this, 'handle_add_subject']);
        add_action('admin_post_reservations_delete_subject', [$this, 'handle_delete_subject']);
        add_action('admin_post_reservations_delete_reservation', [$this, 'handle_delete_reservation']);
    }

    public function load_textdomain()
    {
        load_plugin_textdomain('reservations-personnalise', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function register_rewrite_rules()
    {
        add_rewrite_rule('^reservation-submit/?$', 'index.php?reservation_submit_form=true', 'top');
    }

    public function register_query_vars($vars)
    {
        $vars[] = 'reservation_submit_form';
        return $vars;
    }

    public function handle_rewrite_request()
    {
        if (get_query_var('reservation_submit_form')) {
            $this->handle_form_submission();
            exit;
        }
    }

    public function install_database()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE {$this->table_reservations} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            reservation_date date NOT NULL,
            reservation_time time NOT NULL,
            nom varchar(100) NOT NULL,
            prenom varchar(100) NOT NULL,
            entite varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            sujets text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_slot (reservation_date, reservation_time)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE {$this->table_blocked_slots} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            blocked_date date NOT NULL,
            blocked_time time NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_blocked_slot (blocked_date, blocked_time)
        ) $charset_collate;";

        $sql3 = "CREATE TABLE {$this->table_subjects} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            sujet varchar(255) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY sujet (sujet)
        ) $charset_collate;";

        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);

        // Set default option if not present
        if (get_option('reservations_display_unavailable') === false) {
            update_option('reservations_display_unavailable', 'grey'); // default = grey (griser)
        }

        // Add rewrite rule and flush
        $this->register_rewrite_rules();
        flush_rewrite_rules();
    }

    
    public function enqueue_styles()
{
    // Chemin vers le fichier CSS du plugin
    wp_enqueue_style(
        'reservations-style',
        plugin_dir_url(__FILE__) . 'assets/css/reservations.css',
        [],
        filemtime(plugin_dir_path(__FILE__) . 'assets/css/reservations.css') // Pour versionnement automatique
    );
}

    public function enqueue_admin_styles($hook)
    {
        if (strpos($hook, 'reservations') === false) {
            return;
        }
        $css = ".reservations-admin-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.reservations-stats{display:flex;gap:20px;margin-bottom:30px}.stat-box{background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.05);flex:1}.stat-box h3{margin:0 0 10px;font-size:14px;color:#666}.stat-box .number{font-size:32px;font-weight:700;color:#0073aa}.bloquer-form{background:#fff;padding:20px;border-radius:8px;margin-bottom:30px}.bloquer-form label{display:inline-block;margin-right:15px}";
        wp_register_style('reservations-admin-inline-style', false);
        wp_enqueue_style('reservations-admin-inline-style');
        wp_add_inline_style('reservations-admin-inline-style', $css);
    }

    // --- Helpers / configuration ---
    private function get_dates()
    {
        // Static sample dates; change to dynamic logic if desired.
        return [
            '2025-11-18' => 'Mardi 18 nov.',
            '2025-11-19' => 'Mercredi 19 nov.',
            '2025-11-20' => 'Jeudi 20 nov.',
            '2025-11-21' => 'Vendredi 21 nov.',
        ];
    }

    private function get_heures()
    {
        return ['09:00', '10:00', '11:00', '14:00', '15:00', '16:00'];
    }

    private function get_sujets()
    {
        global $wpdb;
        $res = $wpdb->get_col("SELECT sujet FROM {$this->table_subjects} ORDER BY sujet ASC");
        return is_array($res) ? $res : [];
    }

    private function is_slot_available($d, $h)
    {
        global $wpdb;
        $time_db = $h . ':00';
        $count_res = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_reservations} WHERE reservation_date=%s AND reservation_time=%s", $d, $time_db));
        $count_blocked = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_blocked_slots} WHERE blocked_date=%s AND blocked_time=%s", $d, $time_db));
        return ($count_res === 0 && $count_blocked === 0);
    }

    // --- Frontend form ---
    public function render_form()
    {
        $dates = $this->get_dates();
        $heures = $this->get_heures();
        $sujets = $this->get_sujets();

        // Build availability map
        $disponibilites = [];
        foreach ($dates as $date_val => $date_label) {
            $disponibilites[$date_val] = [];
            foreach ($heures as $heure) {
                if ($this->is_slot_available($date_val, $heure)) {
                    $disponibilites[$date_val][] = $heure;
                }
            }
        }

        // Any slots at all?
        $has_slots = false;
        foreach ($disponibilites as $slots) {
            if (!empty($slots)) {
                $has_slots = true;
                break;
            }
        }

        // Display mode from settings
        $display_mode = get_option('reservations_display_unavailable', 'grey'); // 'grey' or 'hide'

        ob_start();
        ?>
        <div class="reservation-form-wrapper">

            <?php
            if (isset($_GET['reservation_success'])) {
                echo '<div class="reservation-message success">' . esc_html(urldecode($_GET['reservation_success'])) . '</div>';
            }
            if (isset($_GET['reservation_error'])) {
                echo '<div class="reservation-message error">' . esc_html(urldecode($_GET['reservation_error'])) . '</div>';
            }
            ?>

            <?php if (!$has_slots): ?>
                <div class="reservation-message error">😔 Désolé, aucun créneau n'est disponible pour le moment.</div>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(home_url('/reservation-submit/')); ?>" class="reservation-form" id="reservation-form">
                    <?php wp_nonce_field('reservation_action', 'reservation_nonce'); ?>
                    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(wp_unslash($_SERVER['REQUEST_URI'])); ?>">

                    <label>📅 Choisissez une date :</label>
                    <div class="date-buttons">
                        <?php
                        $first_available = null;
                        // If display_mode == 'hide', we skip unavailable dates.
                        foreach ($dates as $val => $label):
                            $has_availability = !empty($disponibilites[$val]);

                            if ($display_mode === 'hide' && !$has_availability) {
                                continue; // skip this day entirely
                            }

                            // find the first available date (only pick a date that has availability)
                            if ($first_available === null && $has_availability) {
                                $first_available = $val;
                            }

                            // Build date labels
                            $date_obj = DateTime::createFromFormat('Y-m-d', $val);
                            if (! $date_obj) {
                                $day_name = '';
                                $day_num = '';
                                $month_name = '';
                            } else {
                                $day_name = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'][$date_obj->format('w')];
                                $day_num = $date_obj->format('d');
                                $month_name = ['','Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'][(int)$date_obj->format('n')];
                            }

                            // For grey mode, display disabled class + disable input if no availability
                            $label_classes = 'date-button' . (!$has_availability ? ' disabled' : '');
                            $disabled_attr = !$has_availability ? 'disabled' : '';
                            ?>
                            <label class="<?php echo esc_attr($label_classes); ?>">
                                <input type="radio" name="date" value="<?php echo esc_attr($val); ?>" <?php echo ($val === $first_available) ? 'checked' : ''; ?> <?php echo $disabled_attr; ?> required>
                                <div class="day"><?php echo esc_html($day_name); ?></div>
                                <div class="date-num"><?php echo esc_html($day_num); ?></div>
                                <div class="month"><?php echo esc_html($month_name); ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <label for="heure">🕐 Choisissez une heure :</label>
                    <select name="heure" id="heure" required></select>

                    <label for="nom">Nom :</label>
                    <input type="text" name="nom" id="nom" placeholder="Votre nom" required minlength="2">

                    <label for="prenom">Prénom :</label>
                    <input type="text" name="prenom" id="prenom" placeholder="Votre prénom" required minlength="2">

                    <label for="entite">Entité :</label>
                    <input type="text" name="entite" id="entite" placeholder="Votre entité" required minlength="2">

                    <label for="email">📧 Votre email :</label>
                    <input type="email" name="email" id="email" placeholder="exemple@email.com" required>

                    <label>Sujet de la visite :</label>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
                        <?php if (!empty($sujets)): ?>
                            <?php foreach ($sujets as $s): ?>
                                <label style="display:flex;align-items:center;font-weight:400;">
                                    <input type="checkbox" name="sujets[]" value="<?php echo esc_attr($s); ?>">
                                    <span style="margin-left:8px"><?php echo esc_html($s); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <input type="text" name="sujets_free" placeholder="Décrivez le sujet de la visite" style="width:100%;padding:10px;border:2px solid #e0e0e0;border-radius:6px;">
                            <p style="font-size:12px;color:#666;margin-top:8px">Aucun sujet prédéfini – le texte sera utilisé comme sujet.</p>
                        <?php endif; ?>
                    </div>

                    <button type="submit" name="reserver" id="submit-btn">Réserver mon créneau</button>

                </form>

                <script>
document.addEventListener("DOMContentLoaded", function() {
    const disponibilites = <?php echo json_encode($disponibilites); ?>;
    const toutesHeures = <?php echo json_encode($heures); ?>;
    const dateInputs = document.querySelectorAll("input[name='date']");
    const selectHeure = document.getElementById("heure");
    const form = document.getElementById("reservation-form");
    const submitBtn = document.getElementById("submit-btn");
    const messageDiv = document.getElementById("reservation-ajax-message");

    function updateSelect() {
        const dateSelectionnee = document.querySelector("input[name='date']:checked");
        if (!dateSelectionnee) return;
        const dateVal = dateSelectionnee.value;
        const dispos = disponibilites[dateVal] || [];

        selectHeure.innerHTML = "";

        toutesHeures.forEach(h => {
            const opt = document.createElement("option");
            opt.value = h;
            opt.textContent = h;

            if (!dispos.includes(h)) {
                opt.disabled = true;
                opt.textContent += " (complet)";
                opt.style.color = "#999";
            }

            selectHeure.appendChild(opt);
        });
    }

    // Ajout de la gestion visuelle de la sélection
    function updateSelectedClass() {
        dateInputs.forEach(input => {
            const label = input.closest("label");
            if (input.checked) {
                label.classList.add("selected");
            } else {
                label.classList.remove("selected");
            }
        });
    }

    dateInputs.forEach(el => {
        el.addEventListener("change", function() {
            updateSelect();
            updateSelectedClass();
        });
    });

    // Initial
    updateSelect();
    updateSelectedClass();

    // No AJAX submission, standard form post
});
</script>


            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    // --- Form processing (conservé pour la compatibilité admin) ---
    public function handle_form_submission()
    {
        // Vérification du nonce pour la sécurité
        if (!isset($_POST['reservation_nonce']) || !wp_verify_nonce($_POST['reservation_nonce'], 'reservation_action')) {
            wp_safe_redirect(add_query_arg('reservation_error', urlencode('Erreur de sécurité.'), wp_get_referer()));
            exit;
        }

        // Récupération et nettoyage des champs
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $heure = isset($_POST['heure']) ? sanitize_text_field($_POST['heure']) : '';
        $nom = isset($_POST['nom']) ? sanitize_text_field($_POST['nom']) : '';
        $prenom = isset($_POST['prenom']) ? sanitize_text_field($_POST['prenom']) : '';
        $entite = isset($_POST['entite']) ? sanitize_text_field($_POST['entite']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        $sujets = [];
        if (!empty($_POST['sujets']) && is_array($_POST['sujets'])) {
            foreach ($_POST['sujets'] as $s) {
                $s = sanitize_text_field($s);
                if ($s !== '') $sujets[] = $s;
            }
        }
        // Si aucun sujet coché, regarder si un texte libre est rempli
        if (empty($sujets) && !empty($_POST['sujets_free'])) {
            $free = sanitize_text_field($_POST['sujets_free']);
            if ($free !== '') $sujets[] = $free;
        }

        // Déterminer l'URL de redirection (page d'origine)
        $redirect_url = !empty($_POST['_wp_http_referer']) ? esc_url_raw(wp_unslash($_POST['_wp_http_referer'])) : home_url('/');

        // Vérification des champs obligatoires
        if (empty($date) || empty($heure) || empty($nom) || empty($prenom) || empty($entite) || empty($email) || !is_email($email) || empty($sujets)) {
            wp_safe_redirect(add_query_arg('reservation_error', urlencode('Tous les champs sont obligatoires.'), $redirect_url));
            exit;
        }

        // --- Validation serveur : date/heure valides ---
        $allowed_dates = array_keys($this->get_dates());
        $allowed_heures = $this->get_heures();
        if (!in_array($date, $allowed_dates) || !in_array($heure, $allowed_heures)) {
            wp_safe_redirect(add_query_arg('reservation_error', urlencode('Date ou heure invalide.'), $redirect_url));
            exit;
        }

        // Vérification de la disponibilité du créneau (évite les doublons)
        if (!$this->is_slot_available($date, $heure)) {
            wp_safe_redirect(add_query_arg('reservation_error', urlencode('Désolé, ce créneau n\'est plus disponible.'), $redirect_url));
            exit;
        }

        // Sauvegarde de la réservation
        $saved = $this->save_reservation(compact('date', 'heure', 'nom', 'prenom', 'entite', 'email', 'sujets'));
        
        if ($saved) {
            // Envoi des mails
            $this->send_notifications(compact('date', 'heure', 'nom', 'prenom', 'entite', 'email', 'sujets'));

            wp_safe_redirect(add_query_arg('reservation_success', urlencode("Merci $prenom $nom! Votre rendez-vous est confirmé."), $redirect_url));
            exit;
        } else {
            wp_safe_redirect(add_query_arg('reservation_error', urlencode("Une erreur est survenue lors de l'enregistrement."), $redirect_url));
            exit;
        }
    }

    public function escape_ics_text($text)
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(',', '\,', $text);
        $text = str_replace(';', '\;', $text);
        $text = str_replace("\n", "\\n", $text);
        return $text;
    }

    private function save_reservation($data)
    {
        global $wpdb;
        $inserted = $wpdb->insert($this->table_reservations, [
            'reservation_date' => $data['date'],
            'reservation_time' => $data['heure'] . ':00',
            'nom' => $data['nom'],
            'prenom' => $data['prenom'],
            'entite' => $data['entite'],
            'email' => $data['email'],
            'sujets' => implode(', ', $data['sujets']),
            'created_at' => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s','%s','%s','%s']);

        return (bool) $inserted;
    }

    private function send_notifications($data)
{
    $date = $data['date'];
    $heure = $data['heure'];
    $nom = $data['nom'];
    $prenom = $data['prenom'];
    $entite = $data['entite'];
    $email = $data['email'];
    $sujets_str = implode(', ', $data['sujets']);

    // --- Formater la date en français ---
    $dateObj = new DateTime($date);
    $formatter = new IntlDateFormatter(
        'fr_FR', 
        IntlDateFormatter::FULL,
        IntlDateFormatter::NONE, 
        'Europe/Paris',
        IntlDateFormatter::GREGORIAN
    );
    $date_fr = $formatter->format($dateObj);
    $date_fr = ucfirst($date_fr);

    // Options depuis le back-office
    $from_name = get_option('reservations_mail_from_name', 'L\'équipe');
    $from_email = get_option('reservations_mail_from_email', get_option('admin_email'));
    
    // --- Mail CLIENT ---
    $client_subject = get_option('reservations_mail_client_subject', 'Confirmation de votre rendez-vous');
    $client_message_template = get_option(
        'reservations_mail_client_message',
        "Bonjour {prenom} {nom},\n\nVotre rendez-vous est confirmé pour le {date} à {heure}.\n\nSujet(s): {sujets}\n\nCordialement,\nL'équipe"
    );

    $client_message = str_replace(
        ['{prenom}', '{nom}', '{date}', '{heure}', '{sujets}', '{entite}', '{email}'],
        [$prenom, $nom, $date_fr, $heure, $sujets_str, $entite, $email],
        $client_message_template
    );

    // --- Création de l'ICS ---
    $dtstart = date('Ymd\THis', strtotime("$date $heure"));
    $dtend = date('Ymd\THis', strtotime("$date $heure +1 hour"));
    $uid = uniqid();

    // Échapper les données pour le fichier ICS
    $summary = $this->escape_ics_text("Rendez-vous avec $prenom $nom");
    $description = $this->escape_ics_text("Rendez-vous avec $prenom $nom\nEntité: $entite\nEmail: $email\nSujet(s): $sujets_str");
    $location = $this->escape_ics_text("Votre lieu de rendez-vous");

    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//VotreSite//Reservation Plugin//FR\r\n";
    $ics .= "METHOD:REQUEST\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:$uid\r\n";
    $ics .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    $ics .= "DTSTART:$dtstart\r\n";
    $ics .= "DTEND:$dtend\r\n";
    $ics .= "SUMMARY:$summary\r\n";
    $ics .= "DESCRIPTION:$description\r\n";
    $ics .= "LOCATION:$location\r\n";
    $ics .= "END:VEVENT\r\n";
    $ics .= "END:VCALENDAR\r\n";

    // --- Préparer le mail avec pièce jointe ICS ---
    $boundary = md5(time());

    $headers = [];
    $headers[] = "From: $from_name <$from_email>";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: multipart/mixed; charset=UTF-8; boundary=\"$boundary\"";

    // Corps du mail
    $message_body = "--$boundary\r\n";
    $message_body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message_body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message_body .= nl2br($client_message) . "\r\n\r\n";

    // Pièce jointe ICS
    $message_body .= "--$boundary\r\n";
    $message_body .= "Content-Type: text/calendar; method=REQUEST; name=\"reservation.ics\"\r\n";
    $message_body .= "Content-Disposition: attachment; filename=\"reservation.ics\"\r\n";
    $message_body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message_body .= $ics . "\r\n";
    $message_body .= "--$boundary--";

    // Envoi au client
    wp_mail($email, $client_subject, $message_body, $headers);

    // --- Mail ADMIN ---
    $admin_email_to = get_option('reservations_mail_admin_email', get_option('admin_email'));
    $admin_subject_template = get_option('reservations_mail_admin_subject', "Nouvelle réservation : {date} à {heure}");
    $admin_message_template = get_option(
        'reservations_mail_admin_message',
        "Nouvelle réservation :\nNom : {prenom} {nom}\nEntité : {entite}\nEmail : {email}\nDate : {date}\nHeure : {heure}\nSujet(s) : {sujets}"
    );

    $admin_subject = str_replace(
        ['{prenom}', '{nom}', '{date}', '{heure}', '{sujets}', '{entite}', '{email}'],
        [$prenom, $nom, $date_fr, $heure, $sujets_str, $entite, $email],
        $admin_subject_template
    );

    $admin_message = str_replace(
        ['{prenom}', '{nom}', '{date}', '{heure}', '{sujets}', '{entite}', '{email}'],
        [$prenom, $nom, $date_fr, $heure, $sujets_str, $entite, $email],
        $admin_message_template
    );

    // Envoi au(x) admin(s)
    wp_mail($admin_email_to, $admin_subject, $admin_message, ["From: $from_name <$from_email>"]);
}

    // --- Admin menu & pages ---
    public function add_admin_menu()
    {
        add_menu_page(
            'Réservations',
            'Réservations',
            'manage_options',
            'reservations-admin',
            [$this, 'display_reservations'],
            'dashicons-calendar-alt',
            20
        );

        add_submenu_page(
            'reservations-admin',
            'Créneaux bloqués',
            'Créneaux bloqués',
            'manage_options',
            'reservations-bloques',
            [$this, 'display_bloques']
        );

        add_submenu_page(
            'reservations-admin',
            'Sujets',
            'Sujets',
            'manage_options',
            'reservations-sujets',
            [$this, 'display_sujets']
        );

        // Settings page
        add_submenu_page(
            'reservations-admin',
            'Paramètres',
            'Paramètres',
            'manage_options',
            'reservations-settings',
            [$this, 'display_settings']
        );
    }

    public function display_reservations()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        global $wpdb;
        echo '<div class="wrap"><div class="reservations-admin-header"><h1>📅 Réservations</h1><a class="button button-primary" href="' .
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=reservations_export'), 'export_reservations')) .
            '">⬇️ Exporter CSV</a></div>';

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($this->table_reservations)));
        if (!$table_exists) {
            echo '<div class="notice notice-warning"><p>La table des réservations n\'existe pas encore. Réactivez le plugin pour créer les tables.</p></div></div>';
            return;
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_reservations}");
        echo '<div class="reservations-stats"><div class="stat-box"><h3>Total des réservations</h3><div class="number">' . $total . '</div></div></div>';

        $results = $wpdb->get_results("SELECT * FROM {$this->table_reservations} ORDER BY reservation_date DESC, reservation_time DESC");

        if (!empty($results)) {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Nom</th><th>Prénom</th><th>Entité</th><th>Email</th><th>Date</th><th>Heure</th><th>Sujets</th><th>Actions</th></tr></thead><tbody>';
            foreach ($results as $row) {
                $del_url = wp_nonce_url(admin_url('admin-post.php?action=reservations_delete_reservation&id=' . intval($row->id)), 'delete_reservation_' . intval($row->id));
                echo '<tr><td>' . esc_html($row->nom) . '</td><td>' . esc_html($row->prenom) . '</td><td>' . esc_html($row->entite) . '</td><td><a href="mailto:' . esc_attr($row->email) . '">' . esc_html($row->email) . '</a></td><td>' . esc_html($row->reservation_date) . '</td><td>' . esc_html(substr($row->reservation_time, 0, 5)) . '</td><td>' . esc_html($row->sujets) . '</td><td><a href="' . esc_url($del_url) . '" class="button button-small" onclick="return confirm(\'Confirmer la suppression?\')">Supprimer</a></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Aucune réservation enregistrée pour le moment.</p>';
        }

        echo '</div>';
    }

    public function display_bloques()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        global $wpdb;
        echo '<div class="wrap"><h1>🚫 Créneaux bloqués</h1><div class="bloquer-form"><h2>Bloquer un nouveau créneau</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="reservations_add_blocked_slot">';
        wp_nonce_field('add_blocked_slot', 'add_blocked_nonce');
        echo '<label>Date: <input type="date" name="date" required></label><label>Heure: <select name="heure">';
        foreach ($this->get_heures() as $h) {
            echo '<option value="' . esc_attr($h) . '">' . esc_html($h) . '</option>';
        }
        echo '</select></label><button type="submit" class="button button-primary">Bloquer</button></form></div>';

        $results = $wpdb->get_results("SELECT * FROM {$this->table_blocked_slots} ORDER BY blocked_date, blocked_time");
        if (!empty($results)) {
            echo '<h2>Créneaux actuellement bloqués</h2><table class="wp-list-table widefat fixed striped"><thead><tr><th>Date</th><th>Heure</th><th>Actions</th></tr></thead><tbody>';
            foreach ($results as $row) {
                $del_url = wp_nonce_url(admin_url('admin-post.php?action=reservations_delete_blocked_slot&id=' . intval($row->id)), 'delete_bloque_' . intval($row->id));
                echo '<tr><td>' . esc_html($row->blocked_date) . '</td><td>' . esc_html(substr($row->blocked_time, 0, 5)) . '</td><td><a href="' . esc_url($del_url) . '" class="button button-small" onclick="return confirm(\'Confirmer la suppression?\')">Débloquer</a></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Aucun créneau bloqué.</p>';
        }
        echo '</div>';
    }

    public function display_sujets()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        global $wpdb;
        echo '<div class="wrap"><h1>📋 Sujets de visite</h1><div class="bloquer-form"><h2>Ajouter un sujet</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="reservations_add_subject">';
        wp_nonce_field('add_subject', 'add_subject_nonce');
        echo '<label for="sujet_nom">Nom du sujet :</label><input type="text" id="sujet_nom" name="sujet" required style="width:300px"><button type="submit" class="button button-primary" style="margin-left:10px">Ajouter</button></form></div>';

        $results = $wpdb->get_results("SELECT * FROM {$this->table_subjects} ORDER BY sujet ASC");
        if (!empty($results)) {
            echo '<h2>Sujets actuels</h2><table class="wp-list-table widefat fixed striped"><thead><tr><th>Sujet</th><th>Actions</th></tr></thead><tbody>';
            foreach ($results as $row) {
                $del_url = wp_nonce_url(admin_url('admin-post.php?action=reservations_delete_subject&id=' . intval($row->id)), 'delete_subject_' . intval($row->id));
                echo '<tr><td>' . esc_html($row->sujet) . '</td><td><a href="' . esc_url($del_url) . '" class="button button-small" onclick="return confirm(\'Confirmer la suppression?\')">Supprimer</a></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Aucun sujet.</p>';
        }
        echo '</div>';
    }

    // Settings page: choose grey or hide for unavailable days + mail settings
    public function display_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }

        // --- Gestion des options d'affichage des jours indisponibles ---
        if (isset($_POST['reservations_settings_nonce']) && wp_verify_nonce($_POST['reservations_settings_nonce'], 'save_reservations_settings')) {
            $choice = (isset($_POST['display_unavailable']) && $_POST['display_unavailable'] === 'hide') ? 'hide' : 'grey';
            update_option('reservations_display_unavailable', $choice);
            echo '<div class="updated notice"><p>Paramètres enregistrés.</p></div>';
        }

        $current = get_option('reservations_display_unavailable', 'grey');

        echo '<div class="wrap"><h1>⚙️ Paramètres du module de réservation</h1>';
        echo '<form method="post">';
        wp_nonce_field('save_reservations_settings', 'reservations_settings_nonce');
        echo '<table class="form-table">
            <tr>
                <th scope="row">Affichage des jours sans créneau disponible</th>
                <td>
                    <label><input type="radio" name="display_unavailable" value="grey" ' . checked($current, 'grey', false) . '> Griser les jours</label><br>
                    <label><input type="radio" name="display_unavailable" value="hide" ' . checked($current, 'hide', false) . '> Cacher les jours</label>
                </td>
            </tr>
        </table>';
        echo '<p><input type="submit" class="button button-primary" value="Enregistrer les modifications"></p>';
        echo '</form>';

        // --- Paramètres des mails ---
        // Vérification de la soumission du formulaire mails
        if (isset($_POST['reservations_mail_settings_nonce']) && wp_verify_nonce($_POST['reservations_mail_settings_nonce'], 'save_reservations_mail_settings')) {
            // Utiliser wp_unslash pour enlever les slashes automatiques
            update_option('reservations_mail_from_name', sanitize_text_field(wp_unslash($_POST['from_name'])));
            update_option('reservations_mail_from_email', sanitize_email(wp_unslash($_POST['from_email'])));
            update_option('reservations_mail_client_subject', sanitize_text_field(wp_unslash($_POST['client_subject'])));
            update_option('reservations_mail_client_message', sanitize_textarea_field(wp_unslash($_POST['client_message'])));
            update_option('reservations_mail_admin_subject', sanitize_text_field(wp_unslash($_POST['admin_subject'])));
            update_option('reservations_mail_admin_message', sanitize_textarea_field(wp_unslash($_POST['admin_message'])));
            update_option('reservations_mail_admin_email', sanitize_email(wp_unslash($_POST['admin_email'])));
            echo '<div class="updated notice"><p>Paramètres des mails enregistrés.</p></div>';
        }

        // --- Récupération des valeurs pour affichage ---
        $from_name = get_option('reservations_mail_from_name', "L'équipe");
        $from_email = get_option('reservations_mail_from_email', get_option('admin_email'));
        $client_subject = get_option('reservations_mail_client_subject', 'Confirmation de votre rendez-vous');
        $client_message = get_option('reservations_mail_client_message', "Bonjour {prenom} {nom},\n\nVotre rendez-vous est confirmé pour le {date} à {heure}.\n\nSujet(s): {sujets}\n\nCordialement,\nL'équipe");
        $admin_subject = get_option('reservations_mail_admin_subject', "Nouvelle réservation : {date} à {heure}");
        $admin_message = get_option('reservations_mail_admin_message', "Nouvelle réservation :\nNom : {prenom} {nom}\nEntité : {entite}\nEmail : {email}\nDate : {date}\nHeure : {heure}\nSujet(s) : {sujets}");
        $admin_email = get_option('reservations_mail_admin_email', get_option('admin_email'));

        // --- Formulaire des mails ---
        echo '<form method="post">';
        wp_nonce_field('save_reservations_mail_settings', 'reservations_mail_settings_nonce');
        echo '<table class="form-table">
            <tr><th>Nom de l\'expéditeur</th><td><input type="text" name="from_name" value="' . esc_attr($from_name) . '" style="width:300px"></td></tr>
            <tr><th>Email de l\'expéditeur</th><td><input type="email" name="from_email" value="' . esc_attr($from_email) . '" style="width:300px"></td></tr>
            <tr><th>Objet mail client</th><td><input type="text" name="client_subject" value="' . esc_attr($client_subject) . '" style="width:100%"></td></tr>
            <tr><th>Message mail client</th><td><textarea name="client_message" rows="6" style="width:100%">' . esc_textarea($client_message) . '</textarea></td></tr>
            <tr><th>Objet mail admin</th><td><input type="text" name="admin_subject" value="' . esc_attr($admin_subject) . '" style="width:100%"></td></tr>
            <tr><th>Message mail admin</th><td><textarea name="admin_message" rows="6" style="width:100%">' . esc_textarea($admin_message) . '</textarea></td></tr>
            <tr><th>Email destinataire admin</th><td><input type="email" name="admin_email" value="' . esc_attr($admin_email) . '" style="width:300px"></td></tr>
        </table>';
        echo '<p><input type="submit" class="button button-primary" value="Enregistrer les mails"></p>';
        echo '</form>';

        echo '<p style="font-size:12px;color:#666;">Vous pouvez utiliser les variables : {prenom}, {nom}, {date}, {heure}, {sujets}, {entite}, {email}</p>';
        echo '</div>';
    }

    // Handlers for blocked slots & subjects
    public function handle_add_blocked_slot()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        if (isset($_POST['add_blocked_nonce']) && wp_verify_nonce($_POST['add_blocked_nonce'], 'add_blocked_slot')) {
            global $wpdb;
            $date = sanitize_text_field($_POST['date'] ?? '');
            $heure = sanitize_text_field($_POST['heure'] ?? '');
            if (!empty($date) && !empty($heure)) {
                $wpdb->insert($this->table_blocked_slots, [
                    'blocked_date' => $date,
                    'blocked_time' => $heure . ':00',
                ], ['%s','%s']);
            }
            wp_safe_redirect(admin_url('admin.php?page=reservations-bloques&message=2'));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=reservations-bloques&message=0'));
        exit;
    }

    public function handle_delete_blocked_slot()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        if (isset($_GET['id']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_bloque_' . intval($_GET['id']))) {
            global $wpdb;
            $wpdb->delete($this->table_blocked_slots, ['id' => intval($_GET['id'])], ['%d']);
            wp_safe_redirect(admin_url('admin.php?page=reservations-bloques&message=1'));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=reservations-bloques&message=0'));
        exit;
    }

    public function handle_add_subject()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        if (isset($_POST['add_subject_nonce']) && wp_verify_nonce($_POST['add_subject_nonce'], 'add_subject')) {
            global $wpdb;
            $sujet = sanitize_text_field($_POST['sujet'] ?? '');
            if (!empty($sujet)) {
                $wpdb->insert($this->table_subjects, ['sujet' => $sujet], ['%s']);
            }
            wp_safe_redirect(admin_url('admin.php?page=reservations-sujets&message=1'));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=reservations-sujets&message=0'));
        exit;
    }

    public function handle_delete_subject()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        if (isset($_GET['id']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_subject_' . intval($_GET['id']))) {
            global $wpdb;
            $wpdb->delete($this->table_subjects, ['id' => intval($_GET['id'])], ['%d']);
            wp_safe_redirect(admin_url('admin.php?page=reservations-sujets&message=2'));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=reservations-sujets&message=0'));
        exit;
    }

    public function handle_delete_reservation()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        if (isset($_GET['id']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_reservation_' . intval($_GET['id']))) {
            global $wpdb;
            $wpdb->delete($this->table_reservations, ['id' => intval($_GET['id'])], ['%d']);
            wp_safe_redirect(admin_url('admin.php?page=reservations-admin&message=1'));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=reservations-admin&message=0'));
        exit;
    }

    // CSV export
    public function handle_csv_export()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'export_reservations')) {
            wp_die('Nonce invalide.');
        }
        global $wpdb;
        $results = $wpdb->get_results("SELECT reservation_date,reservation_time,nom,prenom,entite,email,sujets,created_at FROM {$this->table_reservations} ORDER BY reservation_date ASC", ARRAY_A);
        if (empty($results)) {
            wp_die('Aucune réservation');
        }
        header("Content-Type: text/csv; charset=utf-8");
        header("Content-Disposition: attachment; filename=reservations-" . date('Y-m-d') . ".csv");
        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF");
        fputcsv($output, array_keys($results[0]));
        foreach ($results as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}

ReservationsPlugin::get_instance();
