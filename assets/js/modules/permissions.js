window.PERMISSION_MAP = {
    canCreate: ['create_moduli', 'create_task', 'create_document'],
    canEdit: ['edit_moduli', 'edit_task', 'edit_document'],
    canDelete: ['delete_moduli', 'delete_task', 'delete_document'],
    canView: ['view_task', 'view_document'],
    canManageUsers: ['manage_users'],
    canAccessAdminMap: ['view_mappa_admin'],
    canViewGestioneIntranet: ['view_gestione_intranet']
};


window.buildPermissionFlags = function () {
    const userPerms = window.CURRENT_USER?.permissions || [];
    const flags = {};

    for (const logicalKey in PERMISSION_MAP) {
        const perms = PERMISSION_MAP[logicalKey];
        flags[logicalKey] = perms.some(p => userPerms.includes(p));
    }

    return flags;
};
