/**
 * Field type registry + validation pipeline (schema_version envelope).
 *
 * @package Eko_Sampa
 */
(function () {
    'use strict';

    var SCHEMA_VERSION = 1;

    var fieldRegistry = {
        _types: {},

        register: function (type, config) {
            if (!type) {
                return;
            }
            this._types[String(type)] = Object.assign(
                {
                    label: type,
                    icon: 'text',
                    defaults: {},
                    capabilities: {},
                },
                config || {}
            );
        },

        get: function (type) {
            return this._types[String(type)] || null;
        },

        list: function () {
            return Object.keys(this._types);
        },
    };

    fieldRegistry.register('text', { label: 'Text', icon: 'text' });
    fieldRegistry.register('textarea', { label: 'Textarea', icon: 'textarea' });
    fieldRegistry.register('number', { label: 'Number', icon: 'number' });
    fieldRegistry.register('select', { label: 'Select', icon: 'select' });
    fieldRegistry.register('date', { label: 'Date', icon: 'date' });

    var validationEngine = {
        _rules: {},
        _asyncRules: {},

        registerRule: function (name, handler) {
            if (!name || typeof handler !== 'function') {
                return;
            }
            this._rules[String(name)] = handler;
        },

        unwrapStoredRules: function (raw) {
            if (raw == null || raw === '') {
                return { schema_version: SCHEMA_VERSION, rules: null, legacy: null };
            }
            var parsed = raw;
            if (typeof raw === 'string') {
                try {
                    parsed = JSON.parse(raw.trim());
                } catch (e) {
                    return { schema_version: 0, rules: null, legacy: raw };
                }
            }
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                if (parsed.schema_version != null && parsed.rules != null) {
                    return {
                        schema_version: Number(parsed.schema_version) || SCHEMA_VERSION,
                        rules: parsed.rules,
                        legacy: null,
                    };
                }
                return { schema_version: SCHEMA_VERSION, rules: parsed, legacy: null };
            }
            return { schema_version: 0, rules: null, legacy: raw };
        },

        wrapForApi: function (rules) {
            if (rules == null) {
                return null;
            }
            if (typeof rules === 'object' && rules.schema_version != null && rules.rules != null) {
                return rules;
            }
            return {
                schema_version: SCHEMA_VERSION,
                rules: rules,
            };
        },

        /**
         * Run sync validation pipeline on flat field draft.
         *
         * @returns {{ valid: boolean, errors: string[] }}
         */
        validate: function (flatField) {
            var errors = [];
            if (!flatField || !flatField.label) {
                errors.push('Label is required.');
            }
            if (!flatField || !flatField.slug) {
                errors.push('Slug is required.');
            }
            var type = flatField && flatField.type ? String(flatField.type) : 'text';
            if (!fieldRegistry.get(type)) {
                errors.push('Unknown field type.');
            }
            var base = window.ekoSampaFieldValidation;
            if (base && typeof base.buildRestValidationPayload === 'function') {
                var payload = base.buildRestValidationPayload(flatField);
                if (payload === false) {
                    errors.push('Validation rules are invalid.');
                }
            }
            var rk;
            for (rk in this._rules) {
                if (Object.prototype.hasOwnProperty.call(this._rules, rk)) {
                    try {
                        var msg = this._rules[rk](flatField);
                        if (msg) {
                            errors.push(String(msg));
                        }
                    } catch (e) {
                        void e;
                    }
                }
            }
            return { valid: errors.length === 0, errors: errors };
        },

        registerAsyncRule: function (name, handler) {
            if (!name || typeof handler !== 'function') {
                return;
            }
            this._asyncRules[String(name)] = handler;
        },

        /**
         * Sync validation then async rules (remote checks).
         *
         * @param {object} flatField
         * @param {object} [ctx] { serviceId, fieldId, fields[] }
         * @returns {Promise<{ valid: boolean, errors: string[] }>}
         */
        validateAsync: function (flatField, ctx) {
            var self = this;
            ctx = ctx || {};
            var sync = this.validate(flatField);
            if (!sync.valid) {
                return Promise.resolve(sync);
            }
            var keys = Object.keys(this._asyncRules);
            if (!keys.length) {
                return Promise.resolve(sync);
            }
            return Promise.all(
                keys.map(function (name) {
                    return Promise.resolve(self._asyncRules[name](flatField, ctx))
                        .then(function (msg) {
                            return msg ? String(msg) : null;
                        })
                        .catch(function (e) {
                            return e && e.message ? String(e.message) : 'Validation request failed.';
                        });
                })
            ).then(function (messages) {
                var errors = messages.filter(function (m) {
                    return m != null && String(m) !== '';
                });
                return { valid: errors.length === 0, errors: errors };
            });
        },

        buildRestValidationPayload: function (flatField) {
            var base = window.ekoSampaFieldValidation;
            if (!base || typeof base.buildRestValidationPayload !== 'function') {
                return null;
            }
            var rules = base.buildRestValidationPayload(flatField);
            if (rules === false) {
                return false;
            }
            if (rules == null) {
                return null;
            }
            return this.wrapForApi(rules);
        },

        parseFieldRow: function (row) {
            var base = window.ekoSampaFieldValidation;
            if (!base || typeof base.parseFieldRow !== 'function') {
                return { validation: {}, _validationLegacyRaw: null, schema_version: SCHEMA_VERSION };
            }
            var unwrapped = this.unwrapStoredRules(row && row.validation_rules_json);
            var synthetic = Object.assign({}, row || {});
            if (unwrapped.rules != null) {
                synthetic.validation_rules_json = unwrapped.rules;
            } else if (unwrapped.legacy != null) {
                synthetic.validation_rules_json = unwrapped.legacy;
            }
            var parsed = base.parseFieldRow(synthetic);
            parsed.schema_version = unwrapped.schema_version || SCHEMA_VERSION;
            return parsed;
        },
    };

    validationEngine.registerAsyncRule('slugUniqueRemote', function (flatField, ctx) {
        var sid = parseInt(String((ctx && ctx.serviceId) || flatField.service_id || 0), 10);
        var slug = flatField && flatField.slug != null ? String(flatField.slug).trim() : '';
        var exclude = parseInt(String((ctx && ctx.fieldId) || flatField.id || 0), 10) || 0;
        if (!sid || !slug) {
            return null;
        }
        if (!window.ekoSampaApi) {
            return null;
        }
        var qs = new URLSearchParams({ slug: slug });
        if (exclude > 0) {
            qs.set('exclude', String(exclude));
        }
        return window
            .ekoSampaApi('services/' + sid + '/fields/check-slug?' + qs.toString(), { method: 'GET' })
            .then(function (res) {
                if (res && res.available === false) {
                    if (res.conflict_field_id) {
                        return (
                            'Slug "' +
                            (res.slug || slug) +
                            '" is already used by field #' +
                            res.conflict_field_id +
                            '.'
                        );
                    }
                    return 'This slug is already used in this service.';
                }
                return null;
            });
    });

    window.ekoSampaFieldRegistry = fieldRegistry;
    window.ekoSampaValidationEngine = validationEngine;
    window.ekoSampaFieldSchemaVersion = SCHEMA_VERSION;
})();
