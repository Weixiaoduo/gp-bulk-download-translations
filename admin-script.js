/* GP Bulk Download Admin Scripts */

jQuery(document).ready(function($) {
    
    var selectedProjects = [];
    var generatedLink = '';
    
    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).attr('href');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.gpbd-tab-content').hide();
        $(target).show();
    });
    
    // Load projects on page load
    loadProjects();
    
    function loadProjects() {
        $('#project-list').html('<p>加载项目中...</p>');
        
        $.ajax({
            url: gpbdAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'gpbd_get_projects',
                nonce: gpbdAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderProjects(response.data);
                } else {
                    $('#project-list').html('<p style="color:red;">加载失败: ' + response.data + '</p>');
                }
            },
            error: function() {
                $('#project-list').html('<p style="color:red;">加载失败，请刷新页面重试</p>');
            }
        });
    }
    
    function renderProjects(projects) {
        var html = '';
        
        // Build tree structure
        var projectMap = {};
        var rootProjects = [];
        
        projects.forEach(function(project) {
            projectMap[project.id] = project;
            project.children = [];
        });
        
        projects.forEach(function(project) {
            if (project.parent_id && projectMap[project.parent_id]) {
                projectMap[project.parent_id].children.push(project);
            } else {
                rootProjects.push(project);
            }
        });
        
        // Render tree
        function renderProjectItem(project, level) {
            var indent = level > 0 ? 'gpbd-project-child' : '';
            html += '<div class="gpbd-project-item ' + indent + '">';
            html += '<label>';
            html += '<input type="checkbox" class="project-checkbox" value="' + project.path + '" data-name="' + project.name + '">';
            html += '<strong>' + project.name + '</strong>';
            html += ' <span style="color:#666;">(' + project.path + ')</span>';
            html += '</label>';
            html += '</div>';
            
            if (project.children && project.children.length > 0) {
                project.children.forEach(function(child) {
                    renderProjectItem(child, level + 1);
                });
            }
        }
        
        rootProjects.forEach(function(project) {
            renderProjectItem(project, 0);
        });
        
        $('#project-list').html(html || '<p>没有找到项目</p>');
    }
    
    // Project search
    $('#project-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        
        $('.gpbd-project-item').each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(searchTerm) > -1);
        });
    });
    
    // Select/Deselect all
    $('#select-all').on('click', function() {
        $('.project-checkbox:visible').prop('checked', true);
    });
    
    $('#deselect-all').on('click', function() {
        $('.project-checkbox').prop('checked', false);
    });
    
    // Generate link
    $('#generate-link').on('click', function() {
        selectedProjects = [];
        $('.project-checkbox:checked').each(function() {
            selectedProjects.push($(this).val());
        });
        
        if (selectedProjects.length === 0) {
            alert('请至少选择一个项目');
            return;
        }
        
        var flat = $('input[name="structure"]:checked').val() === 'flat';
        var withKey = $('#with-key').is(':checked');
        
        var button = $(this);
        button.addClass('gpbd-loading').prop('disabled', true);
        
        $.ajax({
            url: gpbdAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'gpbd_generate_link',
                nonce: gpbdAdmin.nonce,
                projects: selectedProjects,
                flat: flat,
                format: 'zip',
                with_key: withKey
            },
            success: function(response) {
                button.removeClass('gpbd-loading').prop('disabled', false);
                
                if (response.success) {
                    generatedLink = response.data.url;
                    $('#generated-link').val(generatedLink);
                    $('#filename-preview').text(response.data.filename);
                    $('#copy-link, #direct-download').prop('disabled', false);
                } else {
                    alert('生成失败: ' + response.data);
                }
            },
            error: function() {
                button.removeClass('gpbd-loading').prop('disabled', false);
                alert('生成失败，请重试');
            }
        });
    });
    
    // Copy link
    $('#copy-link').on('click', function() {
        var textarea = document.getElementById('generated-link');
        textarea.select();
        document.execCommand('copy');
        
        var button = $(this);
        var originalText = button.text();
        button.text('已复制！');
        setTimeout(function() {
            button.text(originalText);
        }, 2000);
    });
    
    // Direct download
    $('#direct-download').on('click', function() {
        if (generatedLink) {
            window.location.href = generatedLink;
        }
    });
    
    // Generate random key
    $('#generate-key').on('click', function() {
        var key = generateRandomKey(40);
        $('#new-key-value').text(key);
        $('#new-key-display').show();
    });
    
    // Copy new key
    $('#copy-new-key').on('click', function() {
        var key = $('#new-key-value').text();
        var tempInput = $('<input>');
        $('body').append(tempInput);
        tempInput.val(key).select();
        document.execCommand('copy');
        tempInput.remove();
        
        var button = $(this);
        var originalText = button.text();
        button.text('已复制！');
        setTimeout(function() {
            button.text(originalText);
        }, 2000);
    });
    
    // Generate random key
    function generateRandomKey(length) {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
        var key = '';
        for (var i = 0; i < length; i++) {
            key += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return key;
    }
    
});
