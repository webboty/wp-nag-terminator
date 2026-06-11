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
        // Force reflow then animate in.
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

    function collectContent( $bar ) {
        var $notice = findNotice( $bar );
        return {
            content: $notice.prop( 'outerHTML' ) || '',
            excerpt: $notice.text().substring( 0, 200 ),
            source: $bar.closest( '.notice' ).attr( 'class' ) || '',
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
                // Offer 10-second undo (reloads page so the notice reappears).
                showToast( cfg.i18n.terminated, function () {
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

    $( function () {
        // Hide for me
        $( document ).on( 'click', '.nag-terminator-me', function ( e ) {
            e.preventDefault();
            terminate( $( this ).closest( '.nag-terminator-actions' ), 'user' );
        } );

        // Terminate for everyone: first click shows confirm
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
