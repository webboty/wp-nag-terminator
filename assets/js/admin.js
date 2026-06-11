/* global jQuery, WpNagTerminator */
( function ( $ ) {
    'use strict';

    var cfg = window.WpNagTerminator || {};

    function post( action, data ) {
        return $.ajax( {
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: $.extend(
                {
                    action: action,
                    nonce: cfg.nonce,
                },
                data || {}
            ),
        } );
    }

    function showToast( message, onUndo ) {
        var $toast = $( '<div class="wp-nag-terminator-toast" role="status" aria-live="polite"></div>' )
            .text( message + ' ' );
        if ( typeof onUndo === 'function' ) {
            var $undo = $( '<button type="button" class="undo">' ).text( cfg.i18n.undo );
            $undo.on( 'click', function () {
                $toast.remove();
                onUndo();
            } );
            $toast.append( $undo );
        }
        $( 'body' ).append( $toast );
        $toast[ 0 ].offsetHeight; // eslint-disable-line no-unused-expressions
        $toast.addClass( 'is-visible' );
        setTimeout( function () {
            $toast.removeClass( 'is-visible' );
            setTimeout( function () {
                $toast.remove();
            }, 200 );
        }, 10000 );
    }

    function findNotice( $bar ) {
        return $bar.closest( '.notice, div[class*="notice "]' );
    }

    /**
     * Collect payload for the AJAX call, stripping our own action bar
     * so the archive (Log) only stores the original notice content.
     */
    function collectContent( $bar ) {
        var $notice = findNotice( $bar );
        // Clone the notice and remove the action bar from the clone.
        var $clone = $notice.clone();
        $clone.find( '.nag-terminator-actions' ).remove();
        return {
            content: $clone.prop( 'outerHTML' ) || '',
            excerpt: $notice.text().substring( 0, 200 ).replace( /\s+/g, ' ' ).trim(),
            source: $notice.attr( 'class' ) || '',
        };
    }

    function terminate( $bar, scope ) {
        var nagId = $bar.data( 'nag-id' );
        if ( ! nagId ) {
            return;
        }
        var payload = collectContent( $bar );
        post( 'wp_nag_terminator_terminate', {
            nag_id: nagId,
            scope: scope,
            excerpt: payload.excerpt,
            content: payload.content,
            source: payload.source,
        } )
            .done( function () {
                var $notice = findNotice( $bar );
                $notice.fadeOut( 200, function () {
                    $notice.remove();
                } );
                showToast( cfg.i18n.hidden, function () {
                    post( 'wp_nag_terminator_restore', {
                        nag_id: nagId,
                        scope: scope,
                    } ).done( function () {
                        window.location.reload();
                    } );
                } );
            } )
            .fail( function () {
                showToast( cfg.i18n.error );
            } );
    }

    function restore( $btn ) {
        var nagId = $btn.data( 'nag-id' );
        var scope = $btn.data( 'scope' ) || 'user';
        if ( ! nagId ) {
            return;
        }
        post( 'wp_nag_terminator_restore', {
            nag_id: nagId,
            scope: scope,
        } )
            .done( function () {
                $btn.closest( 'tr' ).fadeOut( 200, function () {
                    $( this ).remove();
                } );
            } )
            .fail( function () {
                alert( cfg.i18n.error );
            } );
    }

    function deleteArchive( $btn ) {
        if ( ! window.confirm( 'Delete permanently?' ) ) {
            return;
        }
        var nagId = $btn.data( 'nag-id' );
        post( 'wp_nag_terminator_delete', { nag_id: nagId } )
            .done( function () {
                $btn.closest( 'tr' ).fadeOut( 200, function () {
                    $( this ).remove();
                } );
            } )
            .fail( function () {
                alert( cfg.i18n.error );
            } );
    }

    /**
     * Show the help modal explaining what the action bar does.
     */
    function showHelpModal() {
        // Don't double up.
        $( '#wp-nag-terminator-modal' ).remove();
        var $overlay = $( '<div id="wp-nag-terminator-modal" class="wp-nag-terminator-modal-overlay" role="dialog" aria-modal="true" />' );
        var $dialog = $( '<div class="wp-nag-terminator-modal" />' );
        $dialog.append( '<h2 class="wp-nag-terminator-modal-title"></h2>' );
        $dialog.append( '<p class="wp-nag-terminator-modal-body"></p>' );
        var $footer = $( '<div class="wp-nag-terminator-modal-footer" />' );
        var $learn = $( '<a class="button button-primary" target="_blank" rel="noopener">' ).text( cfg.i18n.learnMore );
        $learn.attr( 'href', cfg.docsUrl );
        var $close = $( '<button type="button" class="button" />' ).text( cfg.i18n.close );
        $footer.append( $learn ).append( ' ' ).append( $close );
        $dialog.append( $footer );
        $overlay.append( $dialog );
        $( 'body' ).append( $overlay );
        $dialog.find( '.wp-nag-terminator-modal-title' ).text( cfg.i18n.helpTitle );
        $dialog.find( '.wp-nag-terminator-modal-body' ).text( cfg.i18n.helpBody );

        function close() {
            $overlay.fadeOut( 150, function () {
                $overlay.remove();
            } );
            $( document ).off( 'keydown.wpNagTerminatorModal' );
        }
        $close.on( 'click', close );
        $overlay.on( 'click', function ( e ) {
            if ( e.target === $overlay[ 0 ] ) {
                close();
            }
        } );
        $( document ).on( 'keydown.wpNagTerminatorModal', function ( e ) {
            if ( 27 === e.keyCode ) {
                close();
            }
        } );
    }

    $( function () {
        // Hide for me
        $( document ).on( 'click', '.nag-terminator-me', function ( e ) {
            e.preventDefault();
            terminate( $( this ).closest( '.nag-terminator-actions' ), 'user' );
        } );

        // Hide for everyone: first click shows confirm
        $( document ).on( 'click', '.nag-terminator-all', function ( e ) {
            e.preventDefault();
            var $bar = $( this ).closest( '.nag-terminator-actions' );
            $bar.find( '.nag-terminator-confirm' ).show();
        } );

        $( document ).on( 'click', '.nag-terminator-all-no', function ( e ) {
            e.preventDefault();
            $( this ).closest( '.nag-terminator-confirm' ).hide();
        } );

        $( document ).on( 'click', '.nag-terminator-all-yes', function ( e ) {
            e.preventDefault();
            var $bar = $( this ).closest( '.nag-terminator-actions' );
            $bar.find( '.nag-terminator-confirm' ).hide();
            terminate( $bar, 'global' );
        } );

        // Help '?' icon → modal with link to the documentation tab.
        $( document ).on( 'click', '.nag-terminator-help', function ( e ) {
            e.preventDefault();
            showHelpModal();
        } );

        // Tools page: restore + delete
        $( document ).on( 'click', '.wp-nag-terminator-restore', function ( e ) {
            e.preventDefault();
            restore( $( this ) );
        } );

        $( document ).on( 'click', '.wp-nag-terminator-delete', function ( e ) {
            e.preventDefault();
            deleteArchive( $( this ) );
        } );

        // Apply visibility-hover class if user prefers hover mode.
        try {
            var v = document.body.getAttribute( 'data-nag-terminator-vis' );
            if ( v === 'hover' ) {
                $( '.nag-terminator-actions' ).addClass( 'visibility-hover' );
            }
        } catch ( err ) { /* noop */ }
    } );
} )( jQuery );
