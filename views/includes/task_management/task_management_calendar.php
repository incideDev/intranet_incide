<?php
$bacheca_id = $_GET['bacheca_id'] ?? null;
if (!$bacheca_id) {
    die("<p style='color: red;'>❌ ERRORE: `bacheca_id` non è stato definito.</p>");
}
?>

<div id="kanban-calendar-container">
    <div id="kanban-calendar-controls">
        <button id="kanban-prev-month">&lt; Precedente</button>
        <span id="kanban-current-month"></span>
        <button id="kanban-next-month">Successivo &gt;</button>
    </div>
    <div id="kanban-calendar">
        <div id="kanban-calendar-header">
            <div class="kanban-day-header">Lun</div>
            <div class="kanban-day-header">Mar</div>
            <div class="kanban-day-header">Mer</div>
            <div class="kanban-day-header">Gio</div>
            <div class="kanban-day-header">Ven</div>
            <div class="kanban-day-header">Sab</div>
            <div class="kanban-day-header">Dom</div>
        </div>
        <div id="kanban-calendar-body"></div>
    </div>
</div>

<!-- Inclusione del file di stile specifico per il calendario -->
<link rel="stylesheet" href="assets/css/calendar.css">

