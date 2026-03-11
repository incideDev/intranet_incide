export function openModal(modalId, content) {
    $(`#${modalId} .modal-content`).html(content);
    $(`#${modalId}`).css('display', 'block');
}

export function closeModal(modalId) {
    $(`#${modalId}`).css('display', 'none');
}

export function setupModalEvents() {
    $('.close').on('click', function() {
        closeModal('infoModal');
    });

    $(window).on('click', function(event) {
        if (event.target.id === 'infoModal') {
            closeModal('infoModal');
        }
    });
}
