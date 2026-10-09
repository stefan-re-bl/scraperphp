<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Signal extends Model
{
    protected $guarded = [];

    public static function label(string $type): string
    {
        return ['no_website' => 'Sin sitio registrado', 'no_https' => 'Sitio sin HTTPS', 'phone_format_valid' => 'Teléfono utilizable', 'phone_format_invalid' => 'Teléfono a revisar', 'phone_likely_mobile' => 'Posible móvil', 'channel_whatsapp' => 'WhatsApp visible', 'channel_email_visible' => 'Email visible', 'channel_form' => 'Formulario visible', 'booking_business' => 'Actividad con reservas', 'booking_system_visible' => 'Sistema de reserva visible', 'manual_booking_dependency' => 'Reservas manuales', 'manual_contact_dependency' => 'Gestión manual a revisar', 'operational_complexity' => 'Equipo o sedes', 'no_visible_booking' => 'Reserva online a revisar', 'weak_cta' => 'Contacto poco claro', 'weak_website_content' => 'Contenido a revisar', 'website_unreachable' => 'Sitio no verificado', 'hard_to_find_contact' => 'Contacto a revisar', 'ads_active' => 'Invierte en captación', 'clear_offer' => 'Oferta clara', 'observed_problem' => 'Problema confirmado'][$type] ?? str_replace('_', ' ', $type);
    }

    protected function casts(): array
    {
        return ['detected_at' => 'datetime'];
    }
}
