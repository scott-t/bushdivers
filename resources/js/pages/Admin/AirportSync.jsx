import {
  Alert,
  AlertDescription,
  AlertIcon,
  Badge,
  Button,
  Card,
  CardBody,
  Checkbox,
  Collapse,
  Flex,
  FormControl,
  FormLabel,
  Grid,
  GridItem,
  HStack,
  Heading,
  Input,
  Progress,
  Radio,
  RadioGroup,
  Stack,
  Stat,
  StatLabel,
  StatNumber,
  Table,
  Tbody,
  Td,
  Text,
  Th,
  Thead,
  Tr,
  useToast,
} from '@chakra-ui/react'
import axios from 'axios'
import React, { useEffect, useMemo, useState } from 'react'

import AdminLayout from '../../components/layout/AdminLayout'
import { SimType, SimTypeNames } from '../../helpers/simtype.helpers.js'

const typeColor = {
  rename: 'blue',
  promote: 'green',
  ambiguous: 'red',
  relocate: 'orange',
  untag_review: 'purple',
}

const typeLabel = {
  rename: 'Renamed',
  promote: 'Third-party match',
  ambiguous: 'Ambiguous',
  relocate: 'Moved',
  untag_review: 'Missing (in use)',
}

const POLL_INTERVAL_MS = 2000

const POLLING_STATUSES = ['queued', 'processing', 'executing']

const decisionOptions = (item, simName) => {
  const incoming = item.incoming?.identifier
  const existing = item.airport?.identifier

  switch (item.type) {
    case 'rename':
      return [
        { value: 'apply', label: `Rename ${existing} to ${incoming}` },
        { value: 'new', label: `Add ${incoming} as a new airport` },
        { value: 'ignore', label: 'Ignore' },
      ]
    case 'promote':
      return [
        { value: 'apply', label: `Promote to base airport ${incoming}` },
        { value: 'new', label: `Add ${incoming} as a new airport` },
        { value: 'ignore', label: 'Leave third-party airport as-is' },
      ]
    case 'relocate':
      return [
        { value: 'apply', label: `Move ${existing} to the new position` },
        { value: 'new', label: `Add ${incoming} as a new airport` },
        { value: 'ignore', label: 'Ignore' },
      ]
    case 'ambiguous':
      return [
        ...(item.candidates ?? []).map((candidate) => ({
          value: `apply:${candidate.id}`,
          label: `It is ${candidate.identifier} (${candidate.distance_nm} nm)`,
        })),
        { value: 'new', label: `Add ${incoming} as a new airport` },
        { value: 'ignore', label: 'Ignore' },
      ]
    case 'untag_review':
      return [
        { value: 'apply', label: `Remove ${simName} from ${existing}` },
        { value: 'ignore', label: 'Keep' },
      ]
    default:
      return []
  }
}

const decisionValue = (item) => {
  if (!item.decision) {
    return ''
  }

  if (item.type === 'ambiguous' && item.decision === 'apply') {
    return `apply:${item.candidate_id}`
  }

  return item.decision
}

const ConflictParty = ({ party, override, onOverride }) => {
  const [value, setValue] = useState(override ?? '')

  if (party.kind !== 'airport') {
    return (
      <Text fontSize="sm">
        New airport from import{party.name ? ` (${party.name})` : ''}
      </Text>
    )
  }

  return (
    <HStack spacing={2}>
      <Text fontSize="sm" minW="160px">
        Existing {party.current_identifier} (#{party.airport_id})
        {party.op_ids?.length ? '' : ' - not in this import'}
      </Text>
      <Input
        size="sm"
        maxW="160px"
        placeholder="New identifier"
        value={value}
        onChange={(event) => setValue(event.target.value.toUpperCase())}
      />
      <Button
        size="sm"
        onClick={() => onOverride(party.airport_id, value || null)}
      >
        {value ? 'Set identifier' : 'Clear'}
      </Button>
    </HStack>
  )
}

const AirportSync = ({ sessionId: initialSessionId = null }) => {
  const toast = useToast()
  const [file, setFile] = useState(null)
  const [simType, setSimType] = useState(SimType.FS20)
  const [sessionId, setSessionId] = useState(initialSessionId)
  const [session, setSession] = useState(null)
  const [uploading, setUploading] = useState(false)
  const [showUntags, setShowUntags] = useState(false)
  const [includeUntags, setIncludeUntags] = useState(false)

  const status = session?.status ?? null
  const changeset = session?.changeset ?? null
  const summary = changeset?.summary ?? {}
  const operations = changeset?.operations ?? []
  const conflicts = changeset?.conflicts ?? []
  const overrides = changeset?.identifier_overrides ?? {}
  const simName = SimTypeNames[changeset?.sim_type] ?? ''

  const reviewItems = useMemo(
    () => operations.filter((op) => op.requires_review),
    [operations]
  )
  const untags = useMemo(
    () => operations.filter((op) => op.type === 'untag'),
    [operations]
  )

  useEffect(() => {
    if (!sessionId) {
      return
    }

    if (status && !POLLING_STATUSES.includes(status)) {
      return
    }

    const poll = async () => {
      try {
        const { data } = await axios.get(
          `/admin/airports/sync/${sessionId}/status`
        )
        setSession(data)
      } catch {
        toast({
          status: 'error',
          title: 'Failed to fetch sync status',
        })
      }
    }

    poll()

    const interval = setInterval(poll, POLL_INTERVAL_MS)

    return () => clearInterval(interval)
  }, [sessionId, status, toast])

  const progress = useMemo(() => {
    const current = session?.progress?.current ?? 0
    const total = session?.progress?.total ?? 0

    if (!total) {
      return 0
    }

    return Math.round((current / total) * 100)
  }, [session])

  const upload = async () => {
    if (!file) {
      toast({
        status: 'warning',
        title: 'Select a CSV file first',
      })
      return
    }

    setUploading(true)

    const formData = new FormData()
    formData.append('file', file)
    formData.append('sim_type', simType)

    try {
      const { data } = await axios.post('/admin/airports/sync', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      })

      setSessionId(data.sessionId)
      setSession({ status: 'queued' })
    } catch (error) {
      toast({
        status: 'error',
        title: 'Upload failed',
        description: error?.response?.data?.message,
      })
    } finally {
      setUploading(false)
    }
  }

  const post = async (path, payload, errorTitle) => {
    try {
      const { data } = await axios.post(
        `/admin/airports/sync/${sessionId}/${path}`,
        payload
      )
      setSession(data)
    } catch (error) {
      toast({
        status: 'error',
        title: errorTitle,
        description: error?.response?.data?.message,
      })
    }
  }

  const setDecision = (item, value) => {
    const [decision, candidateId] = value.split(':')

    post(
      'resolve',
      {
        itemId: item.id,
        decision,
        candidate_id: candidateId ? Number(candidateId) : null,
      },
      'Failed to save review decision'
    )
  }

  const setOverride = (airportId, identifier) =>
    post(
      'override',
      { airport_id: airportId, identifier },
      'Failed to set identifier'
    )

  const execute = async () => {
    try {
      await axios.post(`/admin/airports/sync/${sessionId}/execute`, {
        include_deactivations: includeUntags,
      })

      toast({ status: 'success', title: 'Execution queued' })
      setSession((prev) => ({ ...(prev ?? {}), status: 'executing' }))
    } catch (error) {
      if (error?.response?.status === 422 && error.response.data?.changeset) {
        setSession(error.response.data)
      }

      toast({
        status: 'error',
        title: 'Execution failed',
        description: error?.response?.data?.message,
      })
    }
  }

  const reset = () => {
    setFile(null)
    setSimType(SimType.FS20)
    setSessionId(null)
    setSession(null)
    setShowUntags(false)
    setIncludeUntags(false)
  }

  const blocked = (summary.unresolved ?? 0) > 0 || conflicts.length > 0

  return (
    <AdminLayout heading="Airport Sync" subHeading="LittleNavMap import">
      {!sessionId && (
        <Card>
          <CardBody>
            <Stack spacing={4}>
              <FormControl isRequired>
                <FormLabel>CSV File</FormLabel>
                <Input
                  type="file"
                  accept=".csv,.txt"
                  onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                />
              </FormControl>

              <FormControl isRequired>
                <FormLabel>Sim Type</FormLabel>
                <RadioGroup value={simType} onChange={setSimType}>
                  <Stack direction="row" spacing={6}>
                    <Radio value={SimType.FS20}>
                      {SimTypeNames[SimType.FS20]}
                    </Radio>
                    <Radio value={SimType.FS24}>
                      {SimTypeNames[SimType.FS24]}
                    </Radio>
                  </Stack>
                </RadioGroup>
              </FormControl>

              <Flex justify="flex-end">
                <Button onClick={upload} isLoading={uploading}>
                  Upload
                </Button>
              </Flex>
            </Stack>
          </CardBody>
        </Card>
      )}

      {['queued', 'processing'].includes(status) && (
        <Card>
          <CardBody>
            <Stack spacing={4}>
              <Text>Analysing airports...</Text>
              <Progress value={progress} hasStripe isAnimated />
              <Text fontSize="sm">
                {session?.progress?.current ?? 0} /{' '}
                {session?.progress?.total ?? 0}
              </Text>
            </Stack>
          </CardBody>
        </Card>
      )}

      {status === 'executing' && (
        <Card>
          <CardBody>
            <Stack spacing={4}>
              <Text>Applying changes...</Text>
              <Progress isIndeterminate />
            </Stack>
          </CardBody>
        </Card>
      )}

      {status === 'ready' && changeset && (
        <Stack spacing={6}>
          {session?.error && (
            <Alert status="error">
              <AlertIcon />
              <AlertDescription>{session.error}</AlertDescription>
            </Alert>
          )}

          <Grid templateColumns="repeat(auto-fit, minmax(140px, 1fr))" gap={4}>
            <GridItem>
              <Stat>
                <StatLabel>Unchanged</StatLabel>
                <StatNumber>{summary.unchanged ?? 0}</StatNumber>
              </Stat>
            </GridItem>
            <GridItem>
              <Stat>
                <StatLabel>Auto updates</StatLabel>
                <StatNumber>{summary.by_type?.update ?? 0}</StatNumber>
              </Stat>
            </GridItem>
            <GridItem>
              <Stat>
                <StatLabel>New airports</StatLabel>
                <StatNumber>{summary.planned?.creates ?? 0}</StatNumber>
              </Stat>
            </GridItem>
            <GridItem>
              <Stat>
                <StatLabel>Identifier changes</StatLabel>
                <StatNumber>
                  {summary.planned?.identifier_changes ?? 0}
                </StatNumber>
              </Stat>
            </GridItem>
            <GridItem>
              <Stat>
                <StatLabel>Unresolved reviews</StatLabel>
                <StatNumber>
                  {summary.unresolved ?? 0} / {summary.review_items ?? 0}
                </StatNumber>
              </Stat>
            </GridItem>
            <GridItem>
              <Stat>
                <StatLabel>Conflicts</StatLabel>
                <StatNumber>{conflicts.length}</StatNumber>
              </Stat>
            </GridItem>
            <GridItem>
              <Stat>
                <StatLabel>Invalid rows</StatLabel>
                <StatNumber>{summary.invalid_rows ?? 0}</StatNumber>
              </Stat>
            </GridItem>
          </Grid>

          {conflicts.length > 0 && (
            <Card>
              <CardBody>
                <Stack spacing={4}>
                  <Heading size="sm">Conflicts</Heading>
                  <Text fontSize="sm">
                    These block execution. Change a review decision below, or
                    give an existing airport a new identifier.
                  </Text>
                  {conflicts.map((conflict, index) => (
                    <Stack
                      key={`${conflict.type}-${conflict.identifier}-${index}`}
                      spacing={2}
                      borderWidth="1px"
                      borderRadius="md"
                      p={3}
                    >
                      <HStack>
                        <Badge colorScheme="red">{conflict.type}</Badge>
                        <Text fontSize="sm">{conflict.message}</Text>
                      </HStack>
                      {conflict.type === 'identifier_conflict' &&
                        conflict.parties.map((party) => (
                          <ConflictParty
                            key={`${party.kind}-${
                              party.airport_id ?? party.op_ids?.[0]
                            }`}
                            party={party}
                            override={overrides[party.airport_id]}
                            onOverride={setOverride}
                          />
                        ))}
                    </Stack>
                  ))}
                </Stack>
              </CardBody>
            </Card>
          )}

          {reviewItems.length > 0 && (
            <Card>
              <CardBody>
                <Stack spacing={4}>
                  <Heading size="sm">Review items</Heading>

                  <Table size="sm">
                    <Thead>
                      <Tr>
                        <Th>Type</Th>
                        <Th>Incoming</Th>
                        <Th>Existing</Th>
                        <Th>Action</Th>
                      </Tr>
                    </Thead>
                    <Tbody>
                      {reviewItems.map((item) => (
                        <Tr key={item.id}>
                          <Td>
                            <Stack spacing={1}>
                              <Badge
                                colorScheme={typeColor[item.type] ?? 'gray'}
                              >
                                {typeLabel[item.type] ?? item.type}
                              </Badge>
                              {item.confidence && (
                                <Badge>{item.confidence}</Badge>
                              )}
                            </Stack>
                          </Td>
                          <Td>
                            {item.incoming ? (
                              <>
                                <Text>{item.incoming.identifier}</Text>
                                <Text fontSize="xs">{item.incoming.name}</Text>
                                <Text fontSize="xs">
                                  {item.incoming.lat}, {item.incoming.lon}
                                </Text>
                              </>
                            ) : (
                              <Text fontSize="xs">Not in import</Text>
                            )}
                          </Td>
                          <Td>
                            {item.airport && (
                              <>
                                <Text>{item.airport.identifier}</Text>
                                <Text fontSize="xs">{item.airport.name}</Text>
                              </>
                            )}
                            {item.distance_nm !== null && (
                              <Text fontSize="xs">{item.distance_nm} nm</Text>
                            )}
                            {item.references > 0 && (
                              <Text fontSize="xs">
                                {item.references} live reference(s)
                              </Text>
                            )}
                            {item.note && (
                              <Text fontSize="xs" color="orange.500">
                                {item.note}
                              </Text>
                            )}
                          </Td>
                          <Td>
                            <RadioGroup
                              value={decisionValue(item)}
                              onChange={(value) => setDecision(item, value)}
                            >
                              <Stack>
                                {decisionOptions(item, simName).map(
                                  (option) => (
                                    <Radio
                                      key={option.value}
                                      value={option.value}
                                    >
                                      {option.label}
                                    </Radio>
                                  )
                                )}
                              </Stack>
                            </RadioGroup>
                          </Td>
                        </Tr>
                      ))}
                    </Tbody>
                  </Table>
                </Stack>
              </CardBody>
            </Card>
          )}

          <Card>
            <CardBody>
              <Stack spacing={3}>
                <Button
                  variant="link"
                  alignSelf="flex-start"
                  onClick={() => setShowUntags((current) => !current)}
                >
                  {showUntags ? 'Hide' : 'Show'} airports missing from this
                  import ({untags.length})
                </Button>

                <Collapse in={showUntags} animateOpacity>
                  <Stack spacing={3}>
                    <Alert status="warning">
                      <AlertIcon />
                      <AlertDescription>
                        Removes {simName} from these airports. Nothing is
                        deleted, and third-party airports are never included.
                        Hubs and airports in use are listed in the review items
                        instead.
                      </AlertDescription>
                    </Alert>

                    <Checkbox
                      isChecked={includeUntags}
                      onChange={(event) =>
                        setIncludeUntags(event.target.checked)
                      }
                    >
                      Remove {simName} from missing airports
                    </Checkbox>

                    <Stack spacing={1}>
                      {untags.map((item) => (
                        <Text key={item.id} fontSize="sm">
                          {item.airport.identifier} - {item.airport.name}
                        </Text>
                      ))}
                    </Stack>
                  </Stack>
                </Collapse>
              </Stack>
            </CardBody>
          </Card>

          <Flex justify="flex-end" align="center" gap={4}>
            <Text fontSize="sm">
              There is no undo - take a database backup first.
            </Text>
            <Button onClick={execute} isDisabled={blocked}>
              Apply Changes
            </Button>
          </Flex>
        </Stack>
      )}

      {status === 'executed' && (
        <Card>
          <CardBody>
            <Stack spacing={4}>
              <Heading size="sm">Execution summary</Heading>
              {Object.entries(session?.execution_summary ?? {}).map(
                ([key, value]) => (
                  <Text key={key}>
                    {key}: {value}
                  </Text>
                )
              )}
              <Button alignSelf="flex-start" onClick={reset}>
                Start new sync
              </Button>
            </Stack>
          </CardBody>
        </Card>
      )}

      {(status === 'failed' || status === 'expired') && (
        <Stack spacing={4}>
          <Alert status="error">
            <AlertIcon />
            <AlertDescription>
              {status === 'expired'
                ? 'This sync session has expired.'
                : `Sync failed: ${session?.error ?? 'Unknown error'}`}
            </AlertDescription>
          </Alert>
          <Button alignSelf="flex-start" onClick={reset}>
            Start new sync
          </Button>
        </Stack>
      )}
    </AdminLayout>
  )
}

export default AirportSync
