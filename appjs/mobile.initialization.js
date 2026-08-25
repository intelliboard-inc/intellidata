var OTHERDATA = this.INIT_OTHERDATA;

// Captured at the top level, where `this` is the compiled component and the
// app's injected providers are reachable. The accessor has been renamed across
// app releases, so try both and treat "not available" as "do not track" rather
// than falling back to a credential the server should not be sending.
var SITES_PROVIDER = this.CoreSitesProvider || this.CoreSites || null;

(function() {

    /**
     * Resolve the app's current authenticated site, or null when unavailable.
     *
     * @returns {Object|null}
     */
    function getCurrentSite() {
        if (!SITES_PROVIDER || typeof SITES_PROVIDER.getCurrentSite !== 'function') {
            return null;
        }

        var site = SITES_PROVIDER.getCurrentSite();

        return (site && typeof site.write === 'function') ? site : null;
    }

    var PAGE_URL = '';
    var PAGETYPES = {
        SITE: OTHERDATA.pagetype_site,
        COURSE: OTHERDATA.pagetype_course,
        MODULE: OTHERDATA.pagetype_module,
    };

    var PARAMS = {
        AJAXFREQUENCY: OTHERDATA.ajaxfrequency,
        INACTIVITY: OTHERDATA.inactivity,
        INTERVAL: OTHERDATA.interval,
        PERIOD: 1000,
        PAGE: '',
        PARAM: '',
        MEDIATRACK: OTHERDATA.mediatrack,
    };

    var TRACKING = {
        COUNTER: 0,
        AJAXCOUNTER: 0,
        WARNINGTIME: 0,
        LOGOUTTIME: 0,
        LASTTIMETRACK: 0,
    };

    function clearCounter() {
        TRACKING.COUNTER = 0;
        TRACKING.WARNINGTIME = 0;
        TRACKING.LOGOUTTIME = 0;
    }

    function registerEvents() {
        document.addEventListener('touchstart', clearCounter);
        document.addEventListener('touchmove', clearCounter);

        console.log('Events (touchstart, touchmove) registered...');
    }

    // Helper function to analyze iframe source for known media services
    function analyzeIframeSource(iframe) {
        var src = iframe.src || '';
        if (src.includes('youtube.com') || src.includes('youtu.be')) {
            return { type: 'youtube', hasMedia: true };
        }

        if (src.includes('vimeo.com')) {
            return { type: 'vimeo', hasMedia: true };
        }

        return { type: 'unknown', hasMedia: false };
    }

    // Helper function to check if element is visible
    function isElementVisible(element) {
        var rect = element.getBoundingClientRect();
        var windowHeight = window.innerHeight || document.documentElement.clientHeight;
        var windowWidth = window.innerWidth || document.documentElement.clientWidth;

        return (
            rect.top < windowHeight &&
            rect.bottom > 0 &&
            rect.left < windowWidth &&
            rect.right > 0
        );
    }

    function mediaTracking() {
        var media = [];
        var status = false;

        // Track Video.js players by checking for vjs-playing class
        var videoJsPlayers = document.querySelectorAll('.video-js');
        if (videoJsPlayers.length) {
            videoJsPlayers.forEach(function(player) {
                // Check if player has vjs-playing class (indicates it's currently playing)
                var isPlaying = player.classList.contains('vjs-playing');
                if (isPlaying) {
                    media.push({
                        paused: false,
                        element: player,
                        type: 'videojs'
                    });
                }
            });
        }

        // Track regular HTML5 media elements
        var internal = document.querySelectorAll('audio,video');
        if (internal.length) {
            internal.forEach(function(element) {
                // Skip Video.js elements as they're handled above
                if (!element.closest('.video-js')) {
                    media.push(element);
                }
            });
        }

        // Track iframe media elements (for cross-origin content)
        var frames = document.querySelectorAll('iframe');
        if (frames.length) {
            frames.forEach(function(frame) {
                // Skip iframe if it's part of a Video.js player (already tracked above)
                if (frame.closest('.video-js')) {
                    return;
                }

                try {
                    // Try to access same-origin iframe content
                    var elements = frame.contentWindow.document.querySelectorAll('audio,video');
                    if (elements.length) {
                        elements.forEach(function(element) {
                            media.push(element);
                        });
                    }
                } catch (e) {
                    // Cross-origin iframe - use alternative tracking methods
                    console.log('IntelliData: Cross-origin iframe detected, using alternative tracking', frame.src);

                    // Check if iframe contains known media services
                    var iframeInfo = analyzeIframeSource(frame);
                    if (iframeInfo.hasMedia) {
                        // For known media services, we can assume potential media activity
                        // when iframe is visible and user is active
                        var isVisible = isElementVisible(frame);
                        if (isVisible) {
                            // Add a virtual media element for tracking purposes
                            media.push({
                                paused: false, // Assume playing if visible
                                src: frame.src,
                                type: iframeInfo.type
                            });
                        }
                    }
                }
            });
        }

        // Check if any media is playing
        if (media.length) {
            media.forEach(function(element) {
                if (!element.paused) {
                    status = true;
                }
            });
        }

        return status;
    }

    // Sending tracking AJAX request via fetch.
    function sendAjaxRequest(init) {
        console.log('Send ajax request ' + PARAMS.PAGE + ' ' + PARAMS.PARAM);
        if (!PARAMS.PAGE || !PARAMS.PARAM) {
            return;
        }

        // The call goes through the app's own authenticated site object. It used
        // to be a raw fetch() to /webservice/rest/server.php carrying a
        // wstoken that the server handed to every app session, which meant the
        // site's shared service token was readable by any logged-in user. The
        // site object signs the request with the current user's own token, so
        // no credential has to be shipped to the client at all, and the server
        // no longer trusts a client-supplied user id.
        var site = getCurrentSite();

        if (!site) {
            console.error('IntelliData: no authenticated site available, tracking skipped');
            return;
        }

        site.write('local_intellidata_save_mobile_tracking', {
            page: PARAMS.PAGE,
            param: PARAMS.PARAM,
            time: TRACKING.LASTTIMETRACK,
            init: init,
        })
            .then(data => {
                TRACKING.LASTTIMETRACK = data.time;
            })
            .catch(error => console.error('Send ajax request error:', error));
    }

    // The launch of event registration methods is in the interval due to the fact
    // that during initialization we have document = null.
    var interval = setInterval(() => {
        if (document.body) {
            registerEvents();

            clearInterval(interval);
        }
    }, 100);

    setInterval(function() {
        if (PARAMS.MEDIATRACK) {
            var status = mediaTracking();
            if (status && !document.hidden) {
                clearCounter();
            }
        }

        if (TRACKING.COUNTER <= PARAMS.INACTIVITY) {
            TRACKING.COUNTER++;
            TRACKING.AJAXCOUNTER++;

            if (TRACKING.AJAXCOUNTER == PARAMS.AJAXFREQUENCY && PARAMS.AJAXFREQUENCY) {

                sendAjaxRequest(0);

                TRACKING.AJAXCOUNTER = 0;
            }
        }
    }, PARAMS.PERIOD);

    // Checking the page for the change.
    setInterval(function() {
        var actualHref = this.location.toString();
        if (PAGE_URL != actualHref) {

            PAGE_URL = actualHref;

            PARAMS.PAGE = PAGETYPES.SITE;
            PARAMS.PARAM = 1;

            var coursesPattern = /courses\/([^\/]+)\/(\d+)(?:\/(\d+))?/;
            var match = actualHref.match(coursesPattern);
            if (match) {
                var pagetype = match[1];
                var courseId = match[2];
                var moduleId = match[3];

                if (pagetype === 'course') {
                    PARAMS.PAGE = PAGETYPES.COURSE;
                    PARAMS.PARAM = parseInt(courseId, 10);
                } else {
                    PARAMS.PAGE = PAGETYPES.MODULE;
                    PARAMS.PARAM = moduleId ? parseInt(moduleId, 10) : 1;
                }
            }

            clearCounter();

            TRACKING.LASTTIMETRACK = 0;

            sendAjaxRequest(1);
        }

    }, 100);
})();