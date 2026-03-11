<div class="task new-task" id="new-task-<?php echo $status['id']; ?>" style="display:none;" data-status-id="<?php echo $status['id']; ?>">
    <div class="task-content">
        <div class="editable-field title-field" 
             contenteditable="true" 
             onfocus="this.innerText=''" 
             onblur="if(this.innerText==='') this.innerText='Titolo...';" 
             data-field="titolo">
            Titolo...
        </div>
        <div class="editable-field description-field" 
             contenteditable="true" 
             onfocus="this.innerText=''" 
             onblur="if(this.innerText==='') this.innerText='Descrizione...';" 
             data-field="descrizione">
            Descrizione...
        </div>
        <div class="editable-field date-field">
            <img src="assets/icons/calendar.png" alt="Calendario" class="icon">
            <input type="date" id="task-date-<?php echo $status['id']; ?>" 
                   class="date-picker" data-field="data_scadenza" 
                   placeholder="Data Scadenza...">
        </div>
        <div class="task-field responsabile-field">
            <div class="responsabili-icons" onclick="toggleResponsabileMenu(this)">
                <span class="add-icon">+</span>
            </div>
            <div class="responsabile-menu hidden">
                <div class="responsabile-menu-header">Seleziona Responsabili</div>
                <?php foreach ($personale as $person): ?>
                    <?php $imagePath = getProfileImagePath($person['Nominativo']); ?>
                    <div class="responsabile-option" 
                        data-id="<?php echo htmlspecialchars($person['user_id']); ?>"
                        onclick="toggleResponsabile(this, 'new-task-<?php echo $status['id']; ?>')">
                        <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="<?php echo htmlspecialchars($person['Nominativo']); ?>" class="profile-icon">
                        <span><?php echo htmlspecialchars($person['Nominativo']); ?></span>
                        <span class="selection-indicator hidden">&#10004;</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
